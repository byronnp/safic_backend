<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Unidades\Http\Requests\BuscarDirectorioRequest;
use App\Modules\Unidades\Http\Resources\DirectorioGaritaResource;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\JsonResponse;

class DirectorioGaritaController
{
    private const MAX_RESULTADOS = 20;

    /**
     * Unidades cuyo código, ocupante vigente o placa coinciden con la búsqueda.
     * Sin paginación: son pocas y la pantalla es de consulta rápida en la garita.
     */
    public function index(BuscarDirectorioRequest $request): JsonResponse
    {
        $buscar = (string) $request->validated('buscar');
        $patron = '%'.addcslashes($buscar, '%_\\').'%';
        // Placa sin guion ni espacios: "pba1234" o "PBA 12" encuentran PBA-1234
        $placa = strtoupper((string) preg_replace('/[\s\-]/', '', $buscar));
        $patronPlaca = '%'.addcslashes($placa, '%_\\').'%';

        $unidades = Unidad::query()
            ->with(['bloque', 'ocupantesVigentes.persona', 'vehiculos'])
            ->where(fn ($q) => $q
                ->where('codigo', 'like', mb_strtoupper($patron))
                ->orWhereHas('ocupantesVigentes.persona', fn ($p) => $p
                    ->whereRaw("(nombres || ' ' || apellidos) ilike ?", [$patron]))
                ->orWhereHas('vehiculos', fn ($v) => $v->whereRaw("replace(placa, '-', '') like ?", [$patronPlaca])))
            ->orderBy('codigo')
            ->limit(self::MAX_RESULTADOS)
            ->get();

        return ApiResponse::ok(DirectorioGaritaResource::collection($unidades)->resolve($request));
    }
}
