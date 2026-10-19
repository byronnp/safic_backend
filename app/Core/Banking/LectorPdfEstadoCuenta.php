<?php

namespace App\Core\Banking;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use Carbon\CarbonImmutable;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Lee el estado de cuenta en PDF del Banco del Pichincha (ver docs/referencias/estado-cuenta-banco-pichincha.md).
 *
 * Cada movimiento es una línea: `dd-Mmm` con la oficina pegada, el N.DOC. pegado a la descripción y, al final, tres montos
 * (débito, crédito y saldo). La fecha no trae año: sale de «FECHA ESTE CORTE». Un movimiento puede venir partido en varias líneas
 * (el N.DOC. cortado y la descripción abajo): se unen. El PDF del ejemplo viene en orden cronológico; si algún día viniera al revés,
 * se detecta comprobando cuál de los dos órdenes cuadra con los saldos. Se devuelve siempre en orden cronológico.
 *
 * Es dinero: si el saldo de alguna línea o el cuadre del resumen no cierran, no se importa nada (mejor pedir el Excel al banco
 * que conciliar con una lectura dudosa).
 */
final class LectorPdfEstadoCuenta
{
    /** Meses del banco: inglés o español, en minúsculas. */
    private const MESES = [
        'jan' => 1, 'ene' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12, 'dic' => 12,
    ];

    public function leer(string $ruta): EstadoCuentaLeido
    {
        try {
            $paginas = (new Parser)->parseFile($ruta)->getPages();
            $texto = implode("\n", array_map(fn ($p): string => $p->getText(), $paginas));
        } catch (Throwable) {
            throw new ApiException('ARCHIVO_ILEGIBLE', 'No se pudo leer el PDF. Descárgalo otra vez desde el banco o pide el estado de cuenta en Excel.', 422);
        }

        return $this->interpretar($texto);
    }

    /** Parte pura (sin PDF): interpreta el texto ya extraído. */
    public function interpretar(string $texto): EstadoCuentaLeido
    {
        if (trim($texto) === '') {
            throw new ApiException('PDF_SIN_TEXTO', 'El PDF no trae texto que leer (parece una imagen escaneada). Pide el estado de cuenta en Excel al banco.', 422);
        }

        $corte = $this->fechaDeCorte($texto);
        $filas = $this->movimientos($texto, $corte);
        if ($filas === []) {
            throw new ApiException('ESTADO_FORMATO_DESCONOCIDO', 'No reconocimos los movimientos del PDF. Solo leemos el estado de cuenta del Banco del Pichincha; para otro banco usa el Excel.', 422);
        }

        $saldoInicial = $this->resumen($texto, 'SALDO\s+ANTERIOR');
        $saldoFinal = $this->resumen($texto, 'SALDO\s+ACTUAL');

        // Cronológico: el que cuadra con los saldos. Si ninguno cuadra, no se importa.
        if (! $this->cuadra($filas, $saldoInicial, $saldoFinal)) {
            $filas = array_reverse($filas);
            if (! $this->cuadra($filas, $saldoInicial, $saldoFinal)) {
                throw new ApiException('PDF_NO_CUADRA', 'Los saldos del PDF no cuadran con sus movimientos, así que no lo importamos para no conciliar mal. Pide el estado de cuenta en Excel al banco.', 422);
            }
        }

        $movimientos = [];
        foreach ($filas as $f) {
            if ($f['monto'] === 0) {
                continue; // línea informativa sin valor
            }
            $movimientos[] = [
                'fecha' => $f['fecha'], 'descripcion' => $f['descripcion'], 'referencia' => $f['referencia'],
                'monto' => Dinero::texto($f['monto']), 'saldo' => Dinero::texto($f['saldo']),
            ];
        }
        if ($movimientos === []) {
            throw new ApiException('ESTADO_SIN_MOVIMIENTOS', 'El archivo no tiene movimientos que leer.', 422);
        }

        return new EstadoCuentaLeido(
            $movimientos,
            $saldoInicial === null ? Dinero::texto($filas[0]['saldo'] - $filas[0]['monto']) : Dinero::texto($saldoInicial),
            Dinero::texto($saldoFinal ?? $filas[array_key_last($filas)]['saldo']),
        );
    }

    /** Fecha de «FECHA ESTE CORTE»: de ahí sale el año de cada movimiento. */
    private function fechaDeCorte(string $texto): CarbonImmutable
    {
        if (preg_match('/FECHA\s+ESTE\s+CORTE[^\d]*(\d{2})-(\d{2})-(\d{4})/iu', $texto, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        throw new ApiException('ESTADO_FORMATO_DESCONOCIDO', 'No encontramos la fecha de corte del PDF. Solo leemos el estado de cuenta completo del Banco del Pichincha; si no, usa el Excel.', 422);
    }

    /**
     * @return list<array{fecha: string, descripcion: string, referencia: string, monto: int, saldo: int}>
     */
    private function movimientos(string $texto, CarbonImmutable $corte): array
    {
        $filas = [];
        foreach ($this->lineasDeMovimiento($texto) as $linea) {
            $patron = '/^\s*(\d{2})-([A-Za-z]{3})\d{2,4}\s+(\d{6,12})(.*?)\s+(-?\d+\.\d{2})\s+(-?\d+\.\d{2})\s+(-?\d+\.\d{2})\s*$/u';
            if (preg_match($patron, $linea, $m) !== 1) {
                continue;
            }
            $mes = self::MESES[mb_strtolower($m[2])] ?? null;
            if ($mes === null || (int) $m[1] < 1 || (int) $m[1] > 31) {
                continue;
            }
            $fecha = $this->conAnio((int) $m[1], $mes, $corte);
            if ($fecha === null) {
                continue;
            }

            $debito = Dinero::centavos($m[5]);
            $credito = Dinero::centavos($m[6]);
            $filas[] = [
                'fecha' => $fecha,
                'descripcion' => mb_substr(trim(preg_replace('/\s+/', ' ', $m[4]) ?? ''), 0, 200),
                'referencia' => mb_substr($m[3], 0, 40),
                'monto' => $credito > 0 ? $credito : -$debito,
                'saldo' => Dinero::centavos($m[7]),
            ];
        }

        return $filas;
    }

    /**
     * Una línea por movimiento. Si el PDF partió uno (fecha y N.DOC. arriba, un dígito del N.DOC. suelto y la descripción con los
     * montos abajo), se unen: el dígito suelto sigue al N.DOC. y la descripción va pegada, como en las líneas completas.
     *
     * @return list<string>
     */
    private function lineasDeMovimiento(string $texto): array
    {
        $inicio = '/^\s*\d{2}-[A-Za-z]{3}\d{2,4}\s+\d{6,12}/u';
        $montos = '/\s-?\d+\.\d{2}\s+-?\d+\.\d{2}\s+-?\d+\.\d{2}\s*$/u';
        $lineas = preg_split('/\R/u', $texto) ?: [];
        $salida = [];

        for ($i = 0, $n = count($lineas); $i < $n; $i++) {
            if (preg_match($inicio, $lineas[$i]) !== 1) {
                continue;
            }
            $unida = rtrim($lineas[$i]);
            // Hasta tres líneas más: dígitos sueltos del N.DOC. y la descripción con los montos
            for ($k = 1; preg_match($montos, $unida) !== 1 && $k <= 3 && $i + $k < $n; $k++) {
                $siguiente = trim($lineas[$i + $k]);
                if ($siguiente === '' || preg_match($inicio, $siguiente) === 1) {
                    break;
                }
                $unida .= $siguiente;
            }
            $salida[] = $unida;
        }

        return $salida;
    }

    /** Año de un día-mes: el del corte, o el anterior si así quedaría después del corte. */
    private function conAnio(int $dia, int $mes, CarbonImmutable $corte): ?string
    {
        foreach ([$corte->year, $corte->year - 1] as $anio) {
            if (! checkdate($mes, $dia, $anio)) {
                continue;
            }
            $fecha = CarbonImmutable::create($anio, $mes, $dia);
            if ($fecha->lessThanOrEqualTo($corte)) {
                return $fecha->toDateString();
            }
        }

        return null;
    }

    private function resumen(string $texto, string $etiqueta): ?int
    {
        return preg_match('/'.$etiqueta.'\D*?(-?\d+\.\d{2})/iu', $texto, $m) === 1 ? Dinero::centavos($m[1]) : null;
    }

    /**
     * El saldo de cada línea debe ser el anterior más su monto, y el resumen debe cuadrar.
     *
     * @param  list<array{fecha: string, descripcion: string, referencia: string, monto: int, saldo: int}>  $filas  en el orden a probar
     */
    private function cuadra(array $filas, ?int $saldoInicial, ?int $saldoFinal): bool
    {
        $anterior = $saldoInicial ?? ($filas[0]['saldo'] - $filas[0]['monto']);
        foreach ($filas as $f) {
            if ($anterior + $f['monto'] !== $f['saldo']) {
                return false;
            }
            $anterior = $f['saldo'];
        }

        return $saldoFinal === null || $anterior === $saldoFinal;
    }
}
