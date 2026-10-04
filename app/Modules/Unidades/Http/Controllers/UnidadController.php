<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Finanzas\Actions\ObtenerMetodoCobroAction;
use App\Modules\Unidades\Actions\ActualizarUnidadAction;
use App\Modules\Unidades\Actions\CrearUnidadAction;
use App\Modules\Unidades\Actions\EliminarUnidadAction;
use App\Modules\Unidades\Http\Requests\GuardarUnidadRequest;
use App\Modules\Unidades\Http\Resources\UnidadDetalleResource;
use App\Modules\Unidades\Http\Resources\UnidadResource;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UnidadController
{
    /**
     * Filtros: bloque_id, tipo, estado, buscar (código o nombre de un ocupante vigente).
     * Orden por código.
     */
    public function index(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar', ''));
        $tipo = (string) $request->query('tipo', '');
        $estado = (string) $request->query('estado', '');
        $bloqueId = $request->query('bloque_id');
        $porPagina = min(max((int) $request->query('por_pagina', 25), 1), 100);
        $patron = '%'.addcslashes($buscar, '%_\\').'%';

        $pagina = Unidad::query()
            ->with(['bloque', 'ocupantesVigentes.persona'])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('codigo', 'like', mb_strtoupper($patron))
                ->orWhereHas('ocupantesVigentes.persona', fn ($p) => $p
                    ->whereRaw("(nombres || ' ' || apellidos) ilike ?", [$patron]))))
            ->when(in_array($tipo, Unidad::TIPOS, true), fn ($q) => $q->where('tipo', $tipo))
            ->when(in_array($estado, Unidad::ESTADOS, true), fn ($q) => $q->conEstado($estado))
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

        // Ocupadas o arrendadas: alguien vive ahí
        $ocupadas = Unidad::query()->whereIn('tipo', LimiteUnidades::TIPOS_CON_CUPO)->conEstado('ocupada')->count()
            + Unidad::query()->whereIn('tipo', LimiteUnidades::TIPOS_CON_CUPO)->conEstado('arrendada')->count();

        // Personas distintas que viven o son dueñas (sin contactos de emergencia)
        $residentes = Ocupante::query()->vigentes()
            ->where('relacion', '!=', 'contacto_emergencia')
            ->distinct()
            ->count('persona_id');

        return ApiResponse::ok([
            'registradas' => $limite->registradas(),
            'total_contratadas' => $limite->total(),
            'suma_alicuotas' => $sumaAlicuotas,
            'metodo_cobro' => $cobro['metodo'],
            'cuota_general' => $cobro['cuota_general'],
            'ocupadas' => $ocupadas,
            'residentes' => $residentes,
        ]);
    }

    public function show(Unidad $unidad): JsonResponse
    {
        return ApiResponse::ok(new UnidadDetalleResource($unidad->load(['bloque', 'ocupantesVigentes.persona'])));
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
