<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Actions\AsignarOcupanteAction;
use App\Modules\Unidades\Actions\FinalizarOcupanteAction;
use App\Modules\Unidades\Http\Requests\AsignarOcupanteRequest;
use App\Modules\Unidades\Http\Requests\FinalizarOcupanteRequest;
use App\Modules\Unidades\Http\Resources\OcupanteResource;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\JsonResponse;

class OcupanteController
{
    /**
     * Historial completo de la unidad: los más recientes primero.
     */
    public function index(Unidad $unidad): JsonResponse
    {
        $ocupantes = $unidad->ocupantes()
            ->with('persona')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::ok(OcupanteResource::collection($ocupantes));
    }

    public function store(AsignarOcupanteRequest $request, Unidad $unidad, AsignarOcupanteAction $asignar): JsonResponse
    {
        /** @var array{persona_id: int, relacion: string, es_principal?: bool, fecha_inicio: string, fecha_fin?: string|null} $datos */
        $datos = $request->validated();

        return ApiResponse::created(new OcupanteResource($asignar->execute($unidad, $datos)), message: 'Ocupante asignado.');
    }

    public function finalizar(FinalizarOcupanteRequest $request, Ocupante $ocupante, FinalizarOcupanteAction $finalizar): JsonResponse
    {
        $ocupante = $finalizar->execute($ocupante, (string) $request->validated('fecha_fin'));

        return ApiResponse::ok(new OcupanteResource($ocupante), message: 'Ocupación finalizada.');
    }
}
