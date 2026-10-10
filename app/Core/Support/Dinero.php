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
     * Reparte un total en centavos según pesos (por ejemplo alícuotas) con el método del mayor
     * resto: cada parte es un número entero de centavos y las partes suman exactamente el total.
     *
     * @param  array<int|string, float|int|string>  $pesos  clave → peso (mayor que cero)
     * @return array<int|string, int> clave → centavos
     */
    public static function repartir(int $total, array $pesos): array
    {
        $suma = 0.0;
        foreach ($pesos as $peso) {
            $suma += (float) $peso;
        }
        if ($suma <= 0.0 || $pesos === []) {
            return array_map(fn () => 0, $pesos);
        }

        $partes = [];
        $restos = [];
        $asignado = 0;
        foreach ($pesos as $clave => $peso) {
            $exacto = $total * ((float) $peso) / $suma;
            $partes[$clave] = (int) floor($exacto);
            $restos[$clave] = $exacto - $partes[$clave];
            $asignado += $partes[$clave];
        }

        // Los centavos que faltan van a quienes tienen el mayor resto (a igual resto, el primero)
        arsort($restos);
        foreach (array_keys($restos) as $clave) {
            if ($asignado >= $total) {
                break;
            }
            $partes[$clave]++;
            $asignado++;
        }

        return $partes;
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
