<?php

namespace App\Core\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Cédula de identidad ecuatoriana: 10 dígitos, provincia 01–24 o 30, tercer dígito
 * menor que 6 y dígito verificador por módulo 10.
 */
final class CedulaEc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::esValida($value)) {
            $fail('La cédula no es válida.');
        }
    }

    public static function esValida(string $cedula): bool
    {
        if (preg_match('/^\d{10}$/', $cedula) !== 1) {
            return false;
        }

        $provincia = (int) substr($cedula, 0, 2);
        if (! (($provincia >= 1 && $provincia <= 24) || $provincia === 30)) {
            return false;
        }

        if ((int) $cedula[2] >= 6) {
            return false;
        }

        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $producto = (int) $cedula[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $producto > 9 ? $producto - 9 : $producto;
        }

        $verificador = (10 - $suma % 10) % 10;

        return $verificador === (int) $cedula[9];
    }
}
