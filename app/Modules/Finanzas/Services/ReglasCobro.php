<?php

namespace App\Modules\Finanzas\Services;

use App\Modules\Finanzas\Models\CobroValorTipo;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use Illuminate\Validation\Rule;

/**
 * Reglas de validación de "cómo cobra el condominio". Las comparten el asistente
 * de alta (campos bajo `cobro.`) y la pantalla Configuración › Cobro de cuotas
 * (campos en la raíz), para que no se separen.
 */
final class ReglasCobro
{
    /**
     * @return array<string, mixed>
     */
    public static function reglas(?string $metodo, string $prefijo = ''): array
    {
        $dinero = ['decimal:0,2', 'gt:0', 'max:99999'];

        return [
            $prefijo.'metodo' => ['required', Rule::in(ConfiguracionCobro::METODOS)],
            $prefijo.'cuota_general' => [Rule::requiredIf($metodo === ConfiguracionCobro::METODO_GENERAL), 'nullable', ...$dinero],
            $prefijo.'presupuesto_mensual' => [Rule::requiredIf($metodo === ConfiguracionCobro::METODO_ALICUOTA), 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999'],
            $prefijo.'valores_tipo' => [Rule::requiredIf($metodo === ConfiguracionCobro::METODO_TIPO), 'nullable', 'array', 'min:1'],
            $prefijo.'valores_tipo.*.tipo' => ['required', 'distinct', Rule::in(CobroValorTipo::TIPOS_UNIDAD)],
            $prefijo.'valores_tipo.*.valor' => ['required', ...$dinero],
            $prefijo.'dia_vencimiento' => ['required', 'integer', 'between:0,28'],
        ];
    }
}
