<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\EmitirPeriodoAction;
use App\Modules\Finanzas\Actions\ListarPeriodosAction;
use App\Modules\Finanzas\Actions\ObtenerResumenFinancieroAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuotas del mes y resumen financiero del condominio.
 */
class PeriodoController
{
    private const FORMATO_MES = ['required', 'string', 'date_format:Y-m'];

    public function index(ListarPeriodosAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function store(Request $request, EmitirPeriodoAction $emitir): JsonResponse
    {
        $datos = $request->validate(['periodo' => self::FORMATO_MES], [
            'periodo.required' => 'Elige el mes a emitir.',
            'periodo.date_format' => 'El mes no es válido (año-mes, por ejemplo 2026-09).',
        ]);

        return ApiResponse::created($emitir->execute($datos['periodo']), message: 'Cuotas emitidas.');
    }

    /** Sin `periodo` es el mes en curso. */
    public function resumen(Request $request, ObtenerResumenFinancieroAction $resumen, Calendario $calendario): JsonResponse
    {
        $datos = $request->validate(['periodo' => ['sometimes', 'string', 'date_format:Y-m']], [
            'periodo.date_format' => 'El mes no es válido (año-mes, por ejemplo 2026-09).',
        ]);

        return ApiResponse::ok($resumen->execute($datos['periodo'] ?? CarbonImmutable::parse($calendario->hoy())->format('Y-m')));
    }
}
