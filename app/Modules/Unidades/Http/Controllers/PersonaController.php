<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Actions\GuardarPersonaAction;
use App\Modules\Unidades\Http\Requests\GuardarPersonaRequest;
use App\Modules\Unidades\Http\Resources\PersonaResource;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaController
{
    /**
     * Filtro: buscar (nombre o documento exacto). Orden por apellidos.
     */
    public function index(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar', ''));
        $porPagina = min(max((int) $request->query('por_pagina', 25), 1), 100);

        $pagina = Persona::query()
            ->when($buscar !== '', fn ($q) => $q->buscar($buscar))
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate($porPagina);

        return ApiResponse::paginated($pagina, PersonaResource::class);
    }

    public function show(Persona $persona): JsonResponse
    {
        return ApiResponse::ok(new PersonaResource($persona));
    }

    public function store(GuardarPersonaRequest $request, GuardarPersonaAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::created(new PersonaResource($guardar->execute($datos)), message: 'Persona registrada.');
    }

    public function update(GuardarPersonaRequest $request, Persona $persona, GuardarPersonaAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::ok(new PersonaResource($guardar->execute($datos, $persona)), message: 'Persona actualizada.');
    }
}
