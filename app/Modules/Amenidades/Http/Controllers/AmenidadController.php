<?php

namespace App\Modules\Amenidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Amenidades\Actions\ActualizarAmenidadAction;
use App\Modules\Amenidades\Actions\AgregarAmenidadAction;
use App\Modules\Amenidades\Actions\ListarAmenidadesAction;
use App\Modules\Amenidades\Actions\OrdenarFotosAmenidadAction;
use App\Modules\Amenidades\Actions\QuitarFotoAmenidadAction;
use App\Modules\Amenidades\Actions\SubirFotoAmenidadAction;
use App\Modules\Amenidades\Http\Requests\ActualizarAmenidadRequest;
use App\Modules\Amenidades\Http\Requests\AgregarAmenidadRequest;
use App\Modules\Amenidades\Http\Requests\SubirFotoAmenidadRequest;
use App\Modules\Amenidades\Models\AmenidadFoto;
use App\Modules\Plataforma\Http\Resources\AmenidadCatalogoResource;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Amenidades del condominio activo y el catálogo de donde se eligen.
 */
class AmenidadController
{
    public function index(ListarAmenidadesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function catalogo(): JsonResponse
    {
        $tipos = AmenidadCatalogo::query()->where('activa', true)->orderBy('orden')->orderBy('nombre')->get();

        return ApiResponse::ok(AmenidadCatalogoResource::collection($tipos));
    }

    public function store(AgregarAmenidadRequest $request, AgregarAmenidadAction $agregar, ListarAmenidadesAction $listar): JsonResponse
    {
        /** @var array{origen: string, amenidad_catalogo_id?: int|null, nombre?: string|null, categoria?: string|null, reservable?: bool, cantidad: int, ubicacion?: string|null} $datos */
        $datos = $request->validated();
        $ids = $agregar->execute($datos);

        $creadas = array_values(array_filter(array_map(fn (int $id) => $listar->uno($id), $ids)));

        return ApiResponse::created($creadas, message: count($creadas) === 1 ? 'Amenidad agregada.' : count($creadas).' amenidades agregadas.');
    }

    public function update(ActualizarAmenidadRequest $request, ActualizarAmenidadAction $actualizar, ListarAmenidadesAction $listar, int $amenidad): JsonResponse
    {
        /** @var array{ubicacion?: string|null, activa?: bool, mantenimiento_hasta?: string|null} $datos */
        $datos = $request->validated();
        $actualizar->execute($amenidad, $datos);

        return ApiResponse::ok($listar->uno($amenidad), message: 'Cambios guardados.');
    }

    public function subirFoto(SubirFotoAmenidadRequest $request, SubirFotoAmenidadAction $subir, ListarAmenidadesAction $listar, int $amenidad): JsonResponse
    {
        $subir->execute($amenidad, $request->file('foto'));

        return ApiResponse::created($listar->uno($amenidad), message: 'Foto agregada.');
    }

    public function quitarFoto(QuitarFotoAmenidadAction $quitar, ListarAmenidadesAction $listar, int $amenidad, int $foto): JsonResponse
    {
        $quitar->execute($amenidad, $foto);

        return ApiResponse::ok($listar->uno($amenidad), message: 'Foto eliminada.');
    }

    public function ordenarFotos(Request $request, OrdenarFotosAmenidadAction $ordenar, ListarAmenidadesAction $listar, int $amenidad): JsonResponse
    {
        $datos = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:'.AmenidadFoto::MAXIMO], 'ids.*' => ['integer']], [
            'ids.required' => 'Indica el orden de las fotos.',
        ]);
        $ordenar->execute($amenidad, $datos['ids']);

        return ApiResponse::ok($listar->uno($amenidad), message: 'Orden de las fotos actualizado.');
    }
}
