<?php

namespace App\Modules\Unidades\Services;

use Illuminate\Support\Str;

/**
 * Columnas del Excel de unidades. Cuáles se piden depende del método de cobro
 * del condominio (el mismo criterio que el formulario de Nueva unidad).
 */
final class ColumnasImportacionUnidades
{
    /** Título de columna (normalizado) → campo de la unidad. */
    private const ALIAS = [
        'codigo' => 'codigo',
        'bloque' => 'bloque',
        'tipo' => 'tipo',
        'piso' => 'piso',
        'area_m2' => 'area_m2',
        'area' => 'area_m2',
        'alicuota' => 'alicuota',
        'cuota_mensual' => 'cuota_mensual',
        'valor_personalizado' => 'valor_personalizado',
        'responsable_pago' => 'responsable_pago',
    ];

    /**
     * Columnas de la plantilla, en orden.
     *
     * @return list<string>
     */
    public static function para(string $metodoCobro): array
    {
        return array_values(array_filter([
            'codigo', 'bloque', 'tipo', 'piso', 'area_m2', 'alicuota',
            $metodoCobro === 'unidad' ? 'cuota_mensual' : null,
            in_array($metodoCobro, ['tipo', 'alicuota'], true) ? 'valor_personalizado' : null,
            'responsable_pago',
        ]));
    }

    /**
     * Columnas sin las cuales no se puede importar.
     *
     * @return list<string>
     */
    public static function obligatorias(string $metodoCobro): array
    {
        return array_values(array_filter([
            'codigo', 'tipo', 'area_m2',
            $metodoCobro === 'alicuota' ? 'alicuota' : null,
            $metodoCobro === 'unidad' ? 'cuota_mensual' : null,
        ]));
    }

    /** Campo de la unidad al que corresponde un título del Excel (null si no se reconoce). */
    public static function campo(string $titulo): ?string
    {
        $clave = (string) Str::of($titulo)->trim()->lower()->ascii()->replaceMatches('/[\s\-\.]+/', '_')->trim('_');

        return self::ALIAS[$clave] ?? null;
    }
}
