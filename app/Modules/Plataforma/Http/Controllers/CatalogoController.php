<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Plataforma\Enums\MetodoCobro;
use App\Modules\Plataforma\Enums\TipoCondominio;
use App\Modules\Plataforma\Http\Resources\AmenidadCatalogoResource;
use App\Modules\Plataforma\Http\Resources\PlanResource;
use App\Modules\Plataforma\Http\Resources\ProvinciaResource;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Plataforma\Models\Provincia;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

/**
 * Catálogos que usa el asistente de alta de condominio (panel de plataforma).
 */
class CatalogoController
{
    public function planes(): JsonResponse
    {
        return ApiResponse::ok(PlanResource::collection(Plan::query()->activos()->get()));
    }

    public function catalogos(): JsonResponse
    {
        return ApiResponse::ok([
            'tipos_condominio' => array_map(fn (TipoCondominio $tipo): array => [
                'valor' => $tipo->value,
                'etiqueta' => $tipo->etiqueta(),
            ], TipoCondominio::cases()),
            'metodos_cobro' => array_map(fn (MetodoCobro $metodo): array => [
                'valor' => $metodo->value,
                'etiqueta' => $metodo->etiqueta(),
                'descripcion' => $metodo->descripcion(),
            ], MetodoCobro::cases()),
            'amenidades' => AmenidadCatalogoResource::collection(AmenidadCatalogo::query()->activas()->get())->resolve(),
        ]);
    }

    public function ubicaciones(): JsonResponse
    {
        $provincias = Provincia::query()
            ->with([
                'cantones' => fn (HasMany $q) => $q->orderBy('nombre'),
                'cantones.parroquias' => fn (HasMany $q) => $q->orderBy('nombre'),
            ])
            ->orderBy('nombre')
            ->get();

        return ApiResponse::ok(ProvinciaResource::collection($provincias));
    }
}
