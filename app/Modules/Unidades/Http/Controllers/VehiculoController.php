<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Actions\EliminarRegistroDeUnidadAction;
use App\Modules\Unidades\Actions\GuardarVehiculoAction;
use App\Modules\Unidades\Http\Requests\GuardarVehiculoRequest;
use App\Modules\Unidades\Http\Resources\VehiculoResource;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VehiculoController
{
    public function store(GuardarVehiculoRequest $request, Unidad $unidad, GuardarVehiculoAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::created(new VehiculoResource($guardar->execute($datos, $unidad)), message: 'Vehículo registrado.');
    }

    public function update(GuardarVehiculoRequest $request, Vehiculo $vehiculo, GuardarVehiculoAction $guardar): JsonResponse
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return ApiResponse::ok(new VehiculoResource($guardar->execute($datos, vehiculo: $vehiculo)), message: 'Vehículo actualizado.');
    }

    public function destroy(Vehiculo $vehiculo, EliminarRegistroDeUnidadAction $eliminar): Response
    {
        $eliminar->execute($vehiculo);

        return ApiResponse::noContent();
    }
}
