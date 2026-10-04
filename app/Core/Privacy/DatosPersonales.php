<?php

namespace App\Core\Privacy;

use RuntimeException;

/**
 * Datos personales (LOPDP): hash para buscar sin descifrar y enmascarado para los
 * roles sin residentes.ver_datos.
 */
final class DatosPersonales
{
    /**
     * HMAC-SHA256 del documento normalizado, con la llave de la aplicación: permite
     * buscar por documento exacto y exigir que no se repita, sin guardarlo en claro.
     * Si APP_KEY cambia, los hashes deben recalcularse.
     */
    public static function hashDocumento(string $tipo, string $documento): string
    {
        $llave = (string) config('app.key');

        if ($llave === '') {
            throw new RuntimeException('Falta APP_KEY para el hash de documentos.');
        }

        return hash_hmac('sha256', $tipo.'|'.self::normalizarDocumento($documento), $llave);
    }

    public static function normalizarDocumento(string $documento): string
    {
        return mb_strtoupper(preg_replace('/[\s.\-]/', '', $documento) ?? '');
    }

    /** "1712345689" → "17••••••89" */
    public static function enmascararDocumento(string $documento): string
    {
        return self::enmascarar($documento, 2, 2);
    }

    /** "0991234534" → "09••••••34" */
    public static function enmascararTelefono(string $telefono): string
    {
        return self::enmascarar($telefono, 2, 2);
    }

    /** "maria.perez@correo.ec" → "m•••@correo.ec" */
    public static function enmascararEmail(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($usuario, 0, 1).'•••'.($dominio === '' ? '' : '@'.$dominio);
    }

    private static function enmascarar(string $valor, int $inicio, int $fin): string
    {
        $largo = mb_strlen($valor);

        if ($largo <= $inicio + $fin) {
            return str_repeat('•', $largo);
        }

        return mb_substr($valor, 0, $inicio).str_repeat('•', $largo - $inicio - $fin).mb_substr($valor, -$fin);
    }
}
