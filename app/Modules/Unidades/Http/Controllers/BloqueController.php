<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Actions\CrearBloqueAction;
use App\Modules\Unidades\Http\Requests\GuardarBloqueRequest;
use App\Modules\Unidades\Http\Resources\BloqueResource;
use App\Modules\Unidades\Models\Bloque;
use Illuminate\Http\JsonResponse;

class BloqueController
{
    public function index(): JsonResponse
    {
        $bloques = Bloque::query()->orderBy('orden')->orderBy('nombre')->get();

        return ApiResponse::ok(BloqueResource::collection($bloques));
    }

    public function store(GuardarBloqueRequest $request, CrearBloqueAction $crear): JsonResponse
    {
        /** @var array{nombre: string, orden?: int} $datos */
        $datos = $request->validated();

        return ApiResponse::created(new BloqueResource($crear->execute($datos)), message: 'Bloque creado.');
    }
}
