<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\GuardarConfiguracionFinanzasAction;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reglas de dinero del condominio: umbral de la segunda aprobación, si quien aprobó una factura
 * puede pagarla y la tolerancia bancaria.
 */
class ConfiguracionFinanzasController
{
    public function show(): JsonResponse
    {
        return ApiResponse::ok(GuardarConfiguracionFinanzasAction::formato(ConfiguracionFinanzas::vigente()));
    }

    public function update(Request $request, GuardarConfiguracionFinanzasAction $guardar): JsonResponse
    {
        $datos = $request->validate([
            'umbral_segunda_aprobacion' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'aprobador_puede_pagar' => ['sometimes', 'boolean'],
            'tolerancia_bancaria' => ['sometimes', 'decimal:0,2', 'between:0,5'],
        ], [
            'umbral_segunda_aprobacion.decimal' => 'El umbral tiene hasta dos decimales.',
            'umbral_segunda_aprobacion.gt' => 'El umbral debe ser mayor a cero.',
            'tolerancia_bancaria.decimal' => 'La tolerancia tiene hasta dos decimales.',
            'tolerancia_bancaria.between' => 'La tolerancia va de $ 0,00 a $ 5,00.',
        ]);

        return ApiResponse::ok($guardar->execute($datos), message: 'Reglas guardadas.');
    }
}
