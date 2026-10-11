<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\CerrarMesAction;
use App\Modules\Finanzas\Actions\ObtenerCierreMesAction;
use App\Modules\Finanzas\Actions\ReabrirMesAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cierre de mes: lista de verificación, cerrar y reabrir.
 */
class CierreMesController
{
    public function show(ObtenerCierreMesAction $obtener, string $periodo): JsonResponse
    {
        return ApiResponse::ok($obtener->execute($periodo));
    }

    public function cerrar(Request $request, CerrarMesAction $cerrar, ObtenerCierreMesAction $obtener, string $periodo): JsonResponse
    {
        $cerrar->execute($request->user(), $periodo);

        return ApiResponse::ok($obtener->execute($periodo), message: 'Mes cerrado.');
    }

    public function reabrir(Request $request, ReabrirMesAction $reabrir, ObtenerCierreMesAction $obtener, string $periodo): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Escribe el motivo de la reapertura.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $reabrir->execute($request->user(), $periodo, $d['motivo']);

        return ApiResponse::ok($obtener->execute($periodo), message: 'Mes reabierto.');
    }
}
