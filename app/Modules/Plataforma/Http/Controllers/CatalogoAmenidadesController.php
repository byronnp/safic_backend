<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Amenidades\Actions\PromoverAmenidadPropiaAction;
use App\Modules\Amenidades\Actions\ResumenAmenidadesPorCondominioAction;
use App\Modules\Plataforma\Actions\EliminarCatalogoAmenidadAction;
use App\Modules\Plataforma\Actions\GuardarCatalogoAmenidadAction;
use App\Modules\Plataforma\Http\Requests\GuardarCatalogoAmenidadRequest;
use App\Modules\Plataforma\Http\Resources\CatalogoAmenidadAdminResource;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo global de amenidades que administra el super admin, y las amenidades propias
 * que crearon los condominios (que se pueden promover al catálogo).
 */
class CatalogoAmenidadesController
{
    public function index(ResumenAmenidadesPorCondominioAction $resumen): JsonResponse
    {
        $uso = $resumen->execute()['uso'];
        $tipos = AmenidadCatalogo::query()->orderBy('orden')->orderBy('nombre')->get();

        return ApiResponse::ok($tipos->map(fn (AmenidadCatalogo $t) => (new CatalogoAmenidadAdminResource($t, $uso))->resolve())->all());
    }

    public function propias(ResumenAmenidadesPorCondominioAction $resumen): JsonResponse
    {
        return ApiResponse::ok($resumen->execute()['propias']);
    }

    public function store(GuardarCatalogoAmenidadRequest $request, GuardarCatalogoAmenidadAction $guardar): JsonResponse
    {
        $tipo = $guardar->execute(null, $request->validated());

        return ApiResponse::created((new CatalogoAmenidadAdminResource($tipo, []))->resolve(), message: 'Amenidad agregada al catálogo.');
    }

    public function update(GuardarCatalogoAmenidadRequest $request, GuardarCatalogoAmenidadAction $guardar, ResumenAmenidadesPorCondominioAction $resumen, int $amenidad): JsonResponse
    {
        $tipo = $guardar->execute($amenidad, $request->validated());

        return ApiResponse::ok((new CatalogoAmenidadAdminResource($tipo, $resumen->execute()['uso']))->resolve(), message: 'Cambios guardados.');
    }

    public function destroy(EliminarCatalogoAmenidadAction $eliminar, int $amenidad): JsonResponse
    {
        $eliminar->execute($amenidad);

        return ApiResponse::ok(null, message: 'Amenidad eliminada del catálogo.');
    }

    public function promover(PromoverAmenidadPropiaAction $promover, int $condominio, int $amenidad): JsonResponse
    {
        $tipo = $promover->execute($condominio, $amenidad);

        return ApiResponse::created((new CatalogoAmenidadAdminResource($tipo, [$tipo->id => 1]))->resolve(), message: 'Amenidad promovida al catálogo global.');
    }
}
