<?php

namespace App\Modules\Unidades\Services;

use App\Core\Http\Exceptions\ApiException;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Lee la primera hoja de un .xlsx y la devuelve como filas con el nombre del
 * campo de cada columna. No ejecuta fórmulas ni macros: solo toma los valores.
 */
final class LectorExcelUnidades
{
    public const MAX_FILAS = 500;

    /**
     * @return array{columnas: list<string>, filas: array<int, array<string, string|null>>} filas por número de fila de Excel
     */
    public function leer(string $ruta, string $metodoCobro): array
    {
        $reader = new Reader;

        try {
            $reader->open($ruta);
            $campos = null;
            $filas = [];

            foreach ($reader->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $numero => $fila) {
                    $celdas = $fila->toArray();

                    if ($campos === null) {
                        $campos = $this->encabezados($celdas, $metodoCobro);

                        continue;
                    }

                    $valores = [];
                    foreach ($campos as $indice => $campo) {
                        $valores[$campo] = $this->texto($celdas[$indice] ?? null);
                    }

                    if (count(array_filter($valores, fn ($v) => $v !== null)) === 0) {
                        continue; // fila vacía
                    }

                    if (count($filas) >= self::MAX_FILAS) {
                        throw new ApiException('IMPORTACION_MUY_GRANDE', 'El archivo tiene más de '.self::MAX_FILAS.' filas. Divídelo en varios archivos.', 422);
                    }

                    $filas[$numero] = $valores;
                }

                break; // solo la primera hoja
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException('IMPORTACION_ARCHIVO_INVALIDO', 'No se pudo leer el archivo. Usa la plantilla de Excel (.xlsx).', 422);
        } finally {
            $reader->close();
        }

        if ($campos === null) {
            throw new ApiException('IMPORTACION_ARCHIVO_INVALIDO', 'El archivo está vacío. Usa la plantilla de Excel (.xlsx).', 422);
        }

        return ['columnas' => array_values($campos), 'filas' => $filas];
    }

    /**
     * @param  array<int, mixed>  $celdas
     * @return array<int, string> índice de columna → campo
     */
    private function encabezados(array $celdas, string $metodoCobro): array
    {
        $campos = [];
        $desconocidas = [];

        foreach ($celdas as $indice => $celda) {
            $titulo = $this->texto($celda);
            if ($titulo === null) {
                continue;
            }

            $campo = ColumnasImportacionUnidades::campo($titulo);
            $campo === null ? $desconocidas[] = $titulo : $campos[$indice] = $campo;
        }

        if ($desconocidas !== []) {
            throw new ApiException('IMPORTACION_COLUMNAS', 'Columnas no reconocidas: '.implode(', ', $desconocidas).'. Usa la plantilla.', 422);
        }

        $faltan = array_diff(ColumnasImportacionUnidades::obligatorias($metodoCobro), $campos);
        if ($faltan !== []) {
            throw new ApiException('IMPORTACION_COLUMNAS', 'Faltan las columnas: '.implode(', ', $faltan).'. Usa la plantilla.', 422);
        }

        if (count($campos) !== count(array_unique($campos))) {
            throw new ApiException('IMPORTACION_COLUMNAS', 'Hay columnas repetidas en el archivo.', 422);
        }

        return $campos;
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_float($valor)) {
            return rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }
}
