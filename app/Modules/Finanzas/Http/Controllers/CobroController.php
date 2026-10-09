<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\ActualizarCobroAction;
use App\Modules\Finanzas\Actions\ObtenerCobroAction;
use App\Modules\Finanzas\Http\Requests\GuardarCobroRequest;
use Illuminate\Http\JsonResponse;

class CobroController
{
    public function show(ObtenerCobroAction $obtener): JsonResponse
    {
        return ApiResponse::ok($obtener->execute());
    }

    public function update(GuardarCobroRequest $request, ActualizarCobroAction $actualizar, ObtenerCobroAction $obtener): JsonResponse
    {
        /** @var array{metodo: string, cuota_general?: string|null, presupuesto_mensual?: string|null, valores_tipo?: list<array{tipo: string, valor: string}>, dia_vencimiento: int, aplica_desde: string} $datos */
        $datos = $request->validated();
        $actualizar->execute($datos);

        return ApiResponse::ok($obtener->execute(), message: 'Cambios guardados.');
    }
}
