<?php

namespace App\Modules\Usuarios\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Usuarios\Actions\DarAccesoResidenteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acceso de los residentes a la app (cuenta ligada a su ficha de persona).
 */
class ResidenteController
{
    public function acceso(Request $request, DarAccesoResidenteAction $dar, int $persona): JsonResponse
    {
        $resultado = $dar->execute($persona, $request->user());

        return ApiResponse::created($resultado, message: $resultado['invitacion_enviada']
            ? 'Acceso creado. Le enviamos el enlace para crear su contraseña.'
            : 'Acceso creado. Ya tenía cuenta: puede entrar con su contraseña.');
    }
}
