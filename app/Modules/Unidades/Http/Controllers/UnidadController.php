<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Finanzas\Actions\ObtenerMetodoCobroAction;
use App\Modules\Unidades\Actions\ActualizarUnidadAction;
use App\Modules\Unidades\Actions\CrearUnidadAction;
use App\Modules\Unidades\Actions\EliminarUnidadAction;
use App\Modules\Unidades\Http\Requests\GuardarUnidadRequest;
use App\Modules\Unidades\Http\Resources\UnidadResource;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UnidadController
{
    /**
     * Filtros: bloque_id, tipo, buscar (código). Orden por código.
     */
    public function index(Request $request): JsonResponse
    {
        $buscar = mb_strtoupper(trim((string) $request->query('buscar', '')));
        $tipo = (string) $request->query('tipo', '');
        $bloqueId = $request->query('bloque_id');
        $porPagina = min(max((int) $request->query('por_pagina', 25), 1), 100);

        $pagina = Unidad::query()
            ->with('bloque')
            ->when($buscar !== '', fn ($q) => $q->where('codigo', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->when(in_array($tipo, Unidad::TIPOS, true), fn ($q) => $q->where('tipo', $tipo))
            ->when(is_string($bloqueId) && ctype_digit($bloqueId), fn ($q) => $q->where('bloque_id', (int) $bloqueId))
            ->orderBy('codigo')
            ->paginate($porPagina);

        return ApiResponse::paginated($pagina, UnidadResource::class);
    }

    /**
     * Tarjetas de la pantalla: avance de carga frente al total contratado,
     * suma de alícuotas y método de cobro (define qué montos pide el formulario).
     */
    public function resumen(LimiteUnidades $limite, ObtenerMetodoCobroAction $metodo): JsonResponse
    {
        // La suma se hace en PostgreSQL como numeric: nunca pasa por float.
        $sumaAlicuotas = (string) Unidad::query()->selectRaw('coalesce(sum(alicuota), 0)::numeric(9,4) as suma')->value('suma');

        $cobro = $metodo->configuracion();

        return ApiResponse::ok([
            'registradas' => $limite->registradas(),
            'total_contratadas' => $limite->total(),
            'suma_alicuotas' => $sumaAlicuotas,
            'metodo_cobro' => $cobro['metodo'],
            'cuota_general' => $cobro['cuota_general'],
        ]);
    }

    public function show(Unidad $unidad): JsonResponse
    {
        return ApiResponse::ok(new UnidadResource($unidad->load('bloque')));
    }

    public function store(GuardarUnidadRequest $request, CrearUnidadAction $crear): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::created(new UnidadResource($crear->execute($datos)), message: 'Unidad creada.');
    }

    public function update(GuardarUnidadRequest $request, Unidad $unidad, ActualizarUnidadAction $actualizar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::ok(new UnidadResource($actualizar->execute($unidad, $datos)), message: 'Unidad actualizada.');
    }

    public function destroy(Unidad $unidad, EliminarUnidadAction $eliminar): Response
    {
        $eliminar->execute($unidad);

        return ApiResponse::noContent();
    }
}
