<?php

namespace App\Core\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Placa vehicular ecuatoriana: autos ABC-1234 (o ABC-123, formato anterior) y motos
 * AB-123C. Se acepta con o sin guion y en minúsculas; normalizar() la deja en el
 * formato oficial.
 */
final class PlacaEc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::normalizar($value) === null) {
            $fail('La placa no es válida (ej. PBA-1234 o una moto IA-123B).');
        }
    }

    /** "pba1234" → "PBA-1234"; "ia123b" → "IA-123B"; null si no es una placa. */
    public static function normalizar(string $placa): ?string
    {
        $limpia = strtoupper((string) preg_replace('/[\s\-]/', '', $placa));

        if (preg_match('/^([A-Z]{3})(\d{3,4})$/', $limpia, $m) === 1) {
            return "{$m[1]}-{$m[2]}";
        }

        if (preg_match('/^([A-Z]{2})(\d{3}[A-Z])$/', $limpia, $m) === 1) {
            return "{$m[1]}-{$m[2]}";
        }

        return null;
    }
}
