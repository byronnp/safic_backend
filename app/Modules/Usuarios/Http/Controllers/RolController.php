<?php

namespace App\Modules\Usuarios\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Usuarios\Actions\ListarRolesAction;
use App\Modules\Usuarios\Actions\SolicitarRolAction;
use App\Modules\Usuarios\Http\Requests\SolicitarRolRequest;
use Illuminate\Http\JsonResponse;

/**
 * Roles que el condominio puede asignar (los define la plataforma) y el pedido de roles nuevos.
 */
class RolController
{
    public function index(ListarRolesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function solicitar(SolicitarRolRequest $request, SolicitarRolAction $solicitar): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{nombre: string, descripcion: string} $datos */
        $datos = $request->validated();

        $solicitud = $solicitar->execute($datos['nombre'], $datos['descripcion'], $user);

        return ApiResponse::created(
            ['id' => $solicitud->id, 'nombre' => $solicitud->nombre, 'estado' => $solicitud->estado],
            message: 'Solicitud enviada a la plataforma.',
        );
    }
}
