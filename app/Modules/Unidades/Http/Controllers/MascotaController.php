<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Actions\EliminarRegistroDeUnidadAction;
use App\Modules\Unidades\Actions\GuardarMascotaAction;
use App\Modules\Unidades\Http\Requests\GuardarMascotaRequest;
use App\Modules\Unidades\Http\Resources\MascotaResource;
use App\Modules\Unidades\Models\Mascota;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MascotaController
{
    public function store(GuardarMascotaRequest $request, Unidad $unidad, GuardarMascotaAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::created(new MascotaResource($guardar->execute($datos, $unidad)), message: 'Mascota registrada.');
    }

    public function update(GuardarMascotaRequest $request, Mascota $mascota, GuardarMascotaAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::ok(new MascotaResource($guardar->execute($datos, mascota: $mascota)), message: 'Mascota actualizada.');
    }

    public function destroy(Mascota $mascota, EliminarRegistroDeUnidadAction $eliminar): Response
    {
        $eliminar->execute($mascota);

        return ApiResponse::noContent();
    }
}
