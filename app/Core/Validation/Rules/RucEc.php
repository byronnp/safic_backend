<?php

namespace App\Core\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RUC ecuatoriano: 13 dígitos, provincia válida y establecimiento distinto de 000.
 * - Persona natural (tercer dígito 0–5): los 10 primeros son una cédula válida.
 * - Sociedad pública (6) o privada (9): solo se valida la estructura, porque el SRI
 *   emite RUC de sociedades que ya no cumplen el dígito verificador por módulo 11.
 */
final class RucEc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::esValido($value)) {
            $fail('El RUC no es válido.');
        }
    }

    public static function esValido(string $ruc): bool
    {
        if (preg_match('/^\d{13}$/', $ruc) !== 1 || str_ends_with($ruc, '000')) {
            return false;
        }

        $provincia = (int) substr($ruc, 0, 2);
        if (! (($provincia >= 1 && $provincia <= 24) || $provincia === 30)) {
            return false;
        }

        $tercero = (int) $ruc[2];

        return match (true) {
            $tercero < 6 => CedulaEc::esValida(substr($ruc, 0, 10)),
            $tercero === 6, $tercero === 9 => true,
            default => false,
        };
    }
}
