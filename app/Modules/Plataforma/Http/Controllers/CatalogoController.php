<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Plataforma\Http\Resources\AmenidadCatalogoResource;
use App\Modules\Plataforma\Http\Resources\PlanResource;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Plataforma\Models\Provincia;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Catálogos que usa el asistente de alta: planes, amenidades y ubicaciones.
 */
class CatalogoController
{
    public function planes(): JsonResponse
    {
        $planes = Plan::query()->where('activo', true)->orderBy('orden')->get();

        return ApiResponse::ok(PlanResource::collection($planes));
    }

    public function amenidades(): JsonResponse
    {
        $amenidades = AmenidadCatalogo::query()->where('activa', true)->orderBy('orden')->orderBy('nombre')->get();

        return ApiResponse::ok(AmenidadCatalogoResource::collection($amenidades));
    }

    /**
     * Provincias → cantones → parroquias del Ecuador en un solo árbol (≈ 80 KB),
     * cacheado: cambia solo cuando se actualiza el catálogo del INEC.
     */
    public function ubicaciones(): JsonResponse
    {
        $arbol = Cache::rememberForever('catalogo.ubicaciones.v1', fn () => Provincia::query()
            ->with(['cantones' => fn ($q) => $q->orderBy('nombre'), 'cantones.parroquias' => fn ($q) => $q->orderBy('nombre')])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Provincia $p) => [
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'latitud' => $p->latitud,
                'longitud' => $p->longitud,
                'cantones' => $p->cantones->map(fn ($c) => [
                    'codigo' => $c->codigo,
                    'nombre' => $c->nombre,
                    'latitud' => $c->latitud,
                    'longitud' => $c->longitud,
                    'parroquias' => $c->parroquias->map(fn ($q) => ['codigo' => $q->codigo, 'nombre' => $q->nombre])->values()->all(),
                ])->values()->all(),
            ])->values()->all());

        return ApiResponse::ok($arbol);
    }
}
