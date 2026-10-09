<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Actions\ActualizarDatosCondominioAction;
use App\Modules\Plataforma\Actions\GuardarLogoCondominioAction;
use App\Modules\Plataforma\Http\Requests\ActualizarDatosCondominioRequest;
use App\Modules\Plataforma\Http\Requests\SubirLogoRequest;
use App\Modules\Plataforma\Http\Resources\DatosCondominioResource;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\JsonResponse;

/**
 * Datos del propio condominio (el del header X-Condominio-Id).
 */
class DatosCondominioController
{
    public function show(TenantContext $tenant): JsonResponse
    {
        $condominio = Condominio::query()->with(['provincia', 'canton', 'parroquia'])->findOrFail($tenant->require());

        return ApiResponse::ok(new DatosCondominioResource($condominio));
    }

    public function update(ActualizarDatosCondominioRequest $request, ActualizarDatosCondominioAction $action): JsonResponse
    {
        $condominio = $action->execute($request->validated());

        return ApiResponse::ok(new DatosCondominioResource($condominio), message: 'Cambios guardados.');
    }

    public function subirLogo(SubirLogoRequest $request, GuardarLogoCondominioAction $action, string $variante): JsonResponse
    {
        $condominio = $action->subir($variante, $request->file('archivo'));

        return ApiResponse::ok(new DatosCondominioResource($condominio), message: 'Logo guardado.');
    }

    public function quitarLogo(GuardarLogoCondominioAction $action, string $variante): JsonResponse
    {
        return ApiResponse::ok(new DatosCondominioResource($action->quitar($variante)));
    }
}
