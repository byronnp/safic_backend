<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Reservas\Actions\GuardarReglasAreaAction;
use App\Modules\Reservas\Actions\ListarAreasAction;
use App\Modules\Reservas\Http\Requests\GuardarReglasAreaRequest;
use Illuminate\Http\JsonResponse;

/**
 * Áreas comunes del condominio y sus reglas de uso.
 */
class AreaController
{
    public function index(ListarAreasAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function show(ListarAreasAction $listar, int $amenidad): JsonResponse
    {
        return ApiResponse::ok($listar->uno($amenidad) ?? abort(404));
    }

    public function reglas(GuardarReglasAreaRequest $request, GuardarReglasAreaAction $guardar, ListarAreasAction $listar, int $amenidad): JsonResponse
    {
        $guardar->execute($amenidad, $request->validated());

        return ApiResponse::ok($listar->uno($amenidad), message: 'Reglas guardadas.');
    }
}
