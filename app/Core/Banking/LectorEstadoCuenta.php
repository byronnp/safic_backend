<?php

namespace App\Core\Banking;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;

/**
 * Lee el estado de cuenta que baja el banco (Excel .xlsx o .csv). Los bancos del país exportan
 * columnas parecidas con nombres distintos: se busca la fila de encabezados y se reconocen las
 * columnas por su nombre (fecha, descripción, referencia, débito, crédito o monto, saldo).
 * No depende de un banco: un banco nuevo con otros nombres se agrega a `ALIAS`.
 */
final class LectorEstadoCuenta
{
    /** Nombres de columna reconocidos (en minúsculas y sin tildes). */
    private const ALIAS = [
        'fecha' => ['fecha', 'fecha contable', 'fecha de transaccion', 'fecha transaccion', 'fecha operacion'],
        'descripcion' => ['descripcion', 'detalle', 'concepto', 'glosa', 'transaccion', 'descripcion de la transaccion'],
        'referencia' => ['referencia', 'documento', 'no documento', 'numero de documento', 'nro documento', 'comprobante', 'ref', 'nro. documento', 'no. documento'],
        'debito' => ['debito', 'debitos', 'egreso', 'egresos', 'retiro', 'retiros', 'cargo', 'cargos'],
        'credito' => ['credito', 'creditos', 'ingreso', 'ingresos', 'deposito', 'depositos', 'abono', 'abonos'],
        'monto' => ['monto', 'valor', 'importe'],
        'saldo' => ['saldo', 'saldo contable', 'saldo disponible'],
    ];

    /** @var int Filas que se revisan buscando los encabezados. */
    private const FILAS_ENCABEZADO = 30;

    public function leer(string $ruta, string $extension): EstadoCuentaLeido
    {
        $filas = $this->filas($ruta, strtolower($extension));

        [$indiceEncabezado, $columnas] = $this->encabezados($filas);

        $movimientos = [];
        foreach (array_slice($filas, $indiceEncabezado + 1) as $fila) {
            $m = $this->movimiento($fila, $columnas);
            if ($m !== null) {
                $movimientos[] = $m;
            }
        }

        if ($movimientos === []) {
            throw new ApiException('ESTADO_SIN_MOVIMIENTOS', 'El archivo no tiene movimientos que leer.', 422);
        }

        // Saldos: el del primer renglón menos su monto es el inicial; el del último, el final
        $primero = $movimientos[0];
        $ultimo = $movimientos[array_key_last($movimientos)];
        $saldoInicial = $primero['saldo'] === null ? null : Dinero::texto(Dinero::centavos($primero['saldo']) - Dinero::centavos($primero['monto']));

        return new EstadoCuentaLeido($movimientos, $saldoInicial, $ultimo['saldo']);
    }

    /**
     * @return list<list<mixed>>
     */
    private function filas(string $ruta, string $extension): array
    {
        $lector = match ($extension) {
            'xlsx' => new LectorXlsx,
            'csv', 'txt' => $this->lectorCsv($ruta),
            default => throw new ApiException('ARCHIVO_NO_SOPORTADO', 'El estado de cuenta debe ser un archivo Excel (.xlsx) o CSV.', 422),
        };

        $filas = [];
        try {
            $lector->open($ruta);
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $fila) {
                    $filas[] = $fila->toArray();
                }
                break; // la primera hoja
            }
            $lector->close();
        } catch (\Throwable $e) {
            throw new ApiException('ARCHIVO_ILEGIBLE', 'No se pudo leer el archivo. Descárgalo otra vez desde el banco.', 422);
        }

        return $filas;
    }

    /** El delimitador se deduce de la primera línea: los bancos usan «;», «,» o tabulador. */
    private function lectorCsv(string $ruta): LectorCsv
    {
        $inicio = (string) file_get_contents($ruta, false, null, 0, 4096);
        $conteo = [';' => substr_count($inicio, ';'), ',' => substr_count($inicio, ','), "\t" => substr_count($inicio, "\t")];
        arsort($conteo);

        $opciones = new OpcionesCsv(FIELD_DELIMITER: (string) array_key_first($conteo));

        return new LectorCsv($opciones);
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @return array{0: int, 1: array<string, int>}
     */
    private function encabezados(array $filas): array
    {
        foreach (array_slice($filas, 0, self::FILAS_ENCABEZADO, true) as $i => $fila) {
            $columnas = [];
            foreach ($fila as $indice => $celda) {
                $nombre = self::normalizar($celda);
                foreach (self::ALIAS as $campo => $alias) {
                    if (! isset($columnas[$campo]) && in_array($nombre, $alias, true)) {
                        $columnas[$campo] = (int) $indice;
                    }
                }
            }

            $hayMonto = isset($columnas['monto']) || isset($columnas['debito']) || isset($columnas['credito']);
            if (isset($columnas['fecha'], $columnas['descripcion']) && $hayMonto) {
                return [(int) $i, $columnas];
            }
        }

        throw new ApiException('ESTADO_FORMATO_DESCONOCIDO', 'No reconocimos las columnas del archivo. Debe traer fecha, descripción, valores (débito y crédito, o monto) y de preferencia el saldo.', 422);
    }

    /**
     * @param  list<mixed>  $fila
     * @param  array<string, int>  $columnas
     * @return array{fecha: string, descripcion: string, referencia: string|null, monto: string, saldo: string|null}|null
     */
    private function movimiento(array $fila, array $columnas): ?array
    {
        $fecha = self::fecha($fila[$columnas['fecha']] ?? null);
        if ($fecha === null) {
            return null; // totales, notas al pie, filas vacías
        }

        $debito = isset($columnas['debito']) ? self::centavos($fila[$columnas['debito']] ?? null) : null;
        $credito = isset($columnas['credito']) ? self::centavos($fila[$columnas['credito']] ?? null) : null;
        $monto = isset($columnas['monto']) ? self::centavos($fila[$columnas['monto']] ?? null) : null;

        $centavos = match (true) {
            $debito !== null && $debito !== 0 => -abs($debito),
            $credito !== null && $credito !== 0 => abs($credito),
            $monto !== null => $monto,
            default => null,
        };
        if ($centavos === null || $centavos === 0) {
            return null;
        }

        $saldo = isset($columnas['saldo']) ? self::centavos($fila[$columnas['saldo']] ?? null) : null;
        $referencia = isset($columnas['referencia']) ? trim((string) ($fila[$columnas['referencia']] ?? '')) : '';

        return [
            'fecha' => $fecha,
            'descripcion' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($fila[$columnas['descripcion']] ?? '')) ?? ''), 0, 200),
            'referencia' => $referencia === '' ? null : mb_substr($referencia, 0, 40),
            'monto' => Dinero::texto($centavos),
            'saldo' => $saldo === null ? null : Dinero::texto($saldo),
        ];
    }

    private static function normalizar(mixed $celda): string
    {
        $texto = mb_strtolower(trim((string) $celda));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/\s+/', ' ', $texto) ?? '');
    }

    /** Fecha de una celda (objeto de fecha, dd/mm/aaaa, aaaa-mm-dd o dd-mm-aaaa) → aaaa-mm-dd, o null. */
    private static function fecha(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        $t = trim((string) $valor);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $t, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }

        return null;
    }

    /**
     * Número de una celda → centavos, sin pasar por float. Acepta «1.234,56», «1,234.56», «$ 80,00»,
     * «-0.41» y «(12.50)» (paréntesis = negativo). Vacío → null.
     */
    private static function centavos(mixed $valor): ?int
    {
        if ($valor === null) {
            return null;
        }
        if (is_int($valor)) {
            return $valor * 100;
        }

        $t = trim(str_replace(['$', ' ', "\u{a0}"], '', (string) $valor));
        if ($t === '') {
            return null;
        }

        $negativo = str_starts_with($t, '-') || (str_starts_with($t, '(') && str_ends_with($t, ')'));
        $t = ltrim($t, '-+(');
        $t = rtrim($t, ')');

        $coma = strrpos($t, ',');
        $punto = strrpos($t, '.');
        if ($coma !== false && $punto !== false) {
            // El separador que aparece último es el decimal
            $decimal = $coma > $punto ? ',' : '.';
        } elseif ($coma !== false || $punto !== false) {
            $sep = $coma !== false ? ',' : '.';
            $posicion = $coma !== false ? $coma : $punto;
            // Con 1 o 2 dígitos después, es decimal; con 3, son miles
            $decimal = strlen($t) - $posicion - 1 === 3 ? null : $sep;
        } else {
            $decimal = null;
        }

        if ($decimal !== null) {
            $miles = $decimal === ',' ? '.' : ',';
            $t = str_replace($miles, '', $t);
            $t = str_replace($decimal, '.', $t);
        } else {
            $t = str_replace([',', '.'], '', $t);
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $t) !== 1) {
            return null;
        }

        $centavos = Dinero::centavos($t);

        return $negativo ? -$centavos : $centavos;
    }
}
