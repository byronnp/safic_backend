<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\CrearCuotaExtraordinariaAction;
use App\Modules\Finanzas\Actions\ListarCuotasDelMesAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuotas del mes de las unidades y cuotas extraordinarias.
 */
class CuotaController
{
    public function index(Request $request, ListarCuotasDelMesAction $listar, Calendario $calendario): JsonResponse
    {
        $f = $request->validate([
            'periodo' => ['sometimes', 'string', 'date_format:Y-m'],
            'filtro' => ['sometimes', Rule::in(ListarCuotasDelMesAction::FILTROS)],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ], [
            'periodo.date_format' => 'El mes no es válido (año-mes, por ejemplo 2026-10).',
            'filtro.in' => 'Elige todas, pendientes, por aprobar, vencidas o pagadas.',
        ]);

        $r = $listar->execute(
            $f['periodo'] ?? CarbonImmutable::parse($calendario->hoy())->format('Y-m'),
            $f['filtro'] ?? 'todas',
            trim((string) ($f['buscar'] ?? '')),
            (int) ($f['page'] ?? 1),
            (int) ($f['por_pagina'] ?? 50),
        );

        return ApiResponse::ok($r['unidades'], [
            'periodo' => $r['periodo'],
            'resumen' => $r['resumen'],
            'conteos' => $r['conteos'],
            'pagination' => ['page' => (int) ($f['page'] ?? 1), 'per_page' => (int) ($f['por_pagina'] ?? 50), 'total' => $r['total'], 'last_page' => max(1, (int) ceil($r['total'] / (int) ($f['por_pagina'] ?? 50)))],
        ]);
    }

    public function extraordinaria(Request $request, CrearCuotaExtraordinariaAction $crear): JsonResponse
    {
        $datos = $request->validate([
            'detalle' => ['required', 'string', 'min:3', 'max:80'],
            'periodo' => ['required', 'string', 'date_format:Y-m'],
            'vence_el' => ['required', 'date_format:Y-m-d'],
            'modo' => ['required', Rule::in(['por_unidad', 'alicuota'])],
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
        ], [
            'detalle.required' => 'Escribe en qué consiste la cuota (por ejemplo, pintura de fachada).',
            'detalle.min' => 'El detalle es muy corto.',
            'periodo.required' => 'Elige el mes en que se cobra.',
            'vence_el.required' => 'Elige la fecha de vencimiento.',
            'vence_el.date_format' => 'La fecha de vencimiento no es válida.',
            'modo.in' => 'Elige cómo se reparte: igual para todas o según la alícuota.',
            'monto.required' => 'Escribe el monto.',
            'monto.decimal' => 'El monto tiene hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
        ]);

        /** @var array{detalle: string, periodo: string, vence_el: string, modo: string, monto: string} $datos */
        $datos = array_map('strval', $datos);

        return ApiResponse::created($crear->execute($datos), message: 'Cuota extraordinaria creada.');
    }
}
