<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\CancelarConvenioAction;
use App\Modules\Finanzas\Actions\CrearConvenioAction;
use App\Modules\Finanzas\Actions\EnviarRecordatoriosAction;
use App\Modules\Finanzas\Actions\LevantarRestriccionAction;
use App\Modules\Finanzas\Actions\ListarConveniosAction;
use App\Modules\Finanzas\Actions\ListarMorosidadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Morosidad: cartera vencida, recordatorios, convenios de pago y restricción de áreas comunes.
 */
class MorosidadController
{
    public function index(Request $request, ListarMorosidadAction $listar): JsonResponse
    {
        $f = $request->validate([
            'filtro' => ['sometimes', Rule::in(ListarMorosidadAction::FILTROS)],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'por_pagina' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ], ['filtro.in' => 'Elige todas, en mora, restringidas o con convenio.']);

        $pagina = (int) ($f['page'] ?? 1);
        $porPagina = (int) ($f['por_pagina'] ?? 50);
        $r = $listar->execute($f['filtro'] ?? 'todas', trim((string) ($f['buscar'] ?? '')), $pagina, $porPagina);

        return ApiResponse::ok($r['unidades'], [
            'resumen' => $r['resumen'], 'conteos' => $r['conteos'], 'reglas' => $r['reglas'],
            'pagination' => ['page' => $pagina, 'per_page' => $porPagina, 'total' => $r['total'], 'last_page' => max(1, (int) ceil($r['total'] / $porPagina))],
        ]);
    }

    public function recordar(Request $request, EnviarRecordatoriosAction $enviar): JsonResponse
    {
        $d = $request->validate(['unidad_ids' => ['sometimes', 'nullable', 'array', 'max:500'], 'unidad_ids.*' => ['integer']]);

        $ids = isset($d['unidad_ids']) ? array_map('intval', $d['unidad_ids']) : null;
        $r = $enviar->manual($request->user(), $ids);

        return ApiResponse::ok($r, message: $r['enviados'] === 1 ? 'Recordatorio enviado.' : "{$r['enviados']} recordatorios enviados.");
    }

    public function levantar(Request $request, LevantarRestriccionAction $levantar, int $unidad): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Escribe el motivo.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $levantar->execute($request->user(), $unidad, $d['motivo']);

        return ApiResponse::ok(['unidad_id' => $unidad], message: 'Restricción levantada.');
    }

    public function convenios(Request $request, ListarConveniosAction $listar): JsonResponse
    {
        $f = $request->validate(['unidad_id' => ['sometimes', 'integer']]);

        return ApiResponse::ok($listar->execute(isset($f['unidad_id']) ? (int) $f['unidad_id'] : null));
    }

    public function crearConvenio(Request $request, CrearConvenioAction $crear, ListarConveniosAction $listar): JsonResponse
    {
        $d = $request->validate([
            'unidad_id' => ['required', 'integer'],
            'cuotas' => ['required', 'integer', 'between:2,36'],
            'primera_cuota_el' => ['required', 'date_format:Y-m-d'],
        ], [
            'unidad_id.required' => 'Elige la unidad.',
            'cuotas.required' => 'Indica en cuántas cuotas se paga.',
            'cuotas.between' => 'El convenio es de 2 a 36 cuotas.',
            'primera_cuota_el.required' => 'Elige la fecha de la primera cuota.',
            'primera_cuota_el.date_format' => 'La fecha no es válida.',
        ]);

        $c = $crear->execute($request->user(), (int) $d['unidad_id'], (int) $d['cuotas'], $d['primera_cuota_el']);

        return ApiResponse::created(collect($listar->execute((int) $d['unidad_id']))->firstWhere('id', $c->id), message: 'Convenio registrado.');
    }

    public function cancelarConvenio(Request $request, CancelarConvenioAction $cancelar, ListarConveniosAction $listar, int $convenio): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Escribe el motivo de la cancelación.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $c = $cancelar->execute($convenio, $d['motivo']);

        return ApiResponse::ok(collect($listar->execute($c->unidad_id))->firstWhere('id', $c->id), message: 'Convenio cancelado.');
    }
}
