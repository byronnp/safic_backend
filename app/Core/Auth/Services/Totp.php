<?php

namespace App\Core\Auth\Services;

/**
 * Contraseñas de un solo uso por tiempo (TOTP, RFC 6238) con HMAC-SHA1, 6 dígitos y pasos de
 * 30 segundos: lo que entienden Google Authenticator, Microsoft Authenticator, Authy y 1Password.
 * Secretos en Base32 (RFC 4648, sin relleno).
 */
final class Totp
{
    public const PASO_SEGUNDOS = 30;

    public const DIGITOS = 6;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo de 160 bits (el tamaño que recomienda el RFC 4226). */
    public static function generarSecreto(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function paso(?int $instante = null): int
    {
        return intdiv($instante ?? time(), self::PASO_SEGUNDOS);
    }

    /** Código de 6 dígitos de un paso de tiempo. */
    public static function codigo(string $secreto, int $paso): string
    {
        $clave = self::deBase32($secreto);
        $mensaje = pack('J', $paso); // contador de 64 bits, big endian
        $huella = hash_hmac('sha1', $mensaje, $clave, true);

        $desplazamiento = ord($huella[19]) & 0x0F;
        $numero = ((ord($huella[$desplazamiento]) & 0x7F) << 24)
            | (ord($huella[$desplazamiento + 1]) << 16)
            | (ord($huella[$desplazamiento + 2]) << 8)
            | ord($huella[$desplazamiento + 3]);

        return str_pad((string) ($numero % (10 ** self::DIGITOS)), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Paso al que corresponde el código (dentro de la ventana de ±$ventana pasos por desfase del
     * reloj del teléfono), o null si no coincide. Solo acepta pasos posteriores a $ultimoPaso: un
     * código ya usado no sirve de nuevo.
     */
    public static function verificar(string $secreto, string $codigo, int $ultimoPaso = 0, int $ventana = 1, ?int $instante = null): ?int
    {
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';
        if (! preg_match('/^\d{'.self::DIGITOS.'}$/', $codigo)) {
            return null;
        }

        $actual = self::paso($instante);
        $encontrado = null;

        // Se recorre toda la ventana (sin cortar al primer acierto) para no filtrar por tiempo
        for ($paso = $actual - $ventana; $paso <= $actual + $ventana; $paso++) {
            if (hash_equals(self::codigo($secreto, $paso), $codigo) && $paso > $ultimoPaso) {
                $encontrado = $paso;
            }
        }

        return $encontrado;
    }

    /** Dirección otpauth:// que lee la app autenticadora (se muestra como QR). */
    public static function uri(string $secreto, string $cuenta, string $emisor): string
    {
        return 'otpauth://totp/'.rawurlencode($emisor.':'.$cuenta)
            .'?secret='.$secreto
            .'&issuer='.rawurlencode($emisor)
            .'&algorithm=SHA1&digits='.self::DIGITOS.'&period='.self::PASO_SEGUNDOS;
    }

    public static function base32(string $binario): string
    {
        $bits = '';
        foreach (str_split($binario) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $texto = '';
        foreach (str_split($bits, 5) as $trozo) {
            $texto .= self::ALFABETO[bindec(str_pad($trozo, 5, '0'))];
        }

        return $texto;
    }

    public static function deBase32(string $texto): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($texto, '='))) as $caracter) {
            $posicion = strpos(self::ALFABETO, $caracter);
            if ($posicion === false) {
                throw new \InvalidArgumentException('Secreto Base32 no válido.');
            }
            $bits .= str_pad(decbin($posicion), 5, '0', STR_PAD_LEFT);
        }

        $binario = '';
        foreach (str_split($bits, 8) as $trozo) {
            if (strlen($trozo) === 8) {
                $binario .= chr((int) bindec($trozo));
            }
        }

        return $binario;
    }
}
