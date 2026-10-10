<?php

namespace App\Core\Support;

/**
 * Dinero en centavos enteros. Los montos viajan y se guardan como texto decimal ("80.00");
 * para sumar o comparar se pasan a centavos (enteros) y se vuelven a texto. Nunca float.
 */
final class Dinero
{
    /** "12320.50" → 1232050. Acepta hasta dos decimales. */
    public static function centavos(string|int|null $monto): int
    {
        if ($monto === null || $monto === '') {
            return 0;
        }

        $texto = (string) $monto;
        $negativo = str_starts_with($texto, '-');
        [$enteros, $decimales] = array_pad(explode('.', ltrim($texto, '-+'), 2), 2, '');
        $centavos = ((int) $enteros) * 100 + (int) str_pad(substr($decimales, 0, 2), 2, '0');

        return $negativo ? -$centavos : $centavos;
    }

    /** 1232050 → "12320.50" */
    public static function texto(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return $signo.intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  iterable<string|int|null>  $montos
     */
    public static function suma(iterable $montos): int
    {
        $total = 0;
        foreach ($montos as $monto) {
            $total += self::centavos($monto);
        }

        return $total;
    }
}
