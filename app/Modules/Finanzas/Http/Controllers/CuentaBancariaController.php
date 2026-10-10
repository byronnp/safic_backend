<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\GuardarCuentaBancariaAction;
use App\Modules\Finanzas\Actions\ListarCuentasBancariasAction;
use App\Modules\Finanzas\Http\Requests\GuardarCuentaBancariaRequest;
use Illuminate\Http\JsonResponse;

/**
 * Cuentas bancarias del condominio (adonde los residentes transfieren).
 */
class CuentaBancariaController
{
    public function index(ListarCuentasBancariasAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function store(GuardarCuentaBancariaRequest $request, GuardarCuentaBancariaAction $guardar, ListarCuentasBancariasAction $listar): JsonResponse
    {
        $cuenta = $guardar->crear($request->validated());

        return ApiResponse::created($listar->uno($cuenta->id), message: 'Cuenta agregada.');
    }

    public function update(GuardarCuentaBancariaRequest $request, GuardarCuentaBancariaAction $guardar, ListarCuentasBancariasAction $listar, int $cuenta): JsonResponse
    {
        $guardar->editar($cuenta, $request->validated());

        return ApiResponse::ok($listar->uno($cuenta), message: 'Cambios guardados.');
    }
}
