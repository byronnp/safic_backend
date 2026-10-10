<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Unidades\Models\Persona;

/**
 * Caso de uso del módulo Unidades: guarda la cuenta con la que una persona entra al sistema.
 * Una persona tiene una sola cuenta.
 */
final class VincularCuentaPersonaAction
{
    public function execute(int $personaId, int $userId): Persona
    {
        $persona = Persona::query()->lockForUpdate()->findOrFail($personaId);

        if ($persona->user_id !== null && $persona->user_id !== $userId) {
            throw new ApiException('PERSONA_CON_OTRA_CUENTA', 'Esta persona ya tiene acceso con otra cuenta.', 409);
        }

        $persona->forceFill(['user_id' => $userId])->save();

        return $persona;
    }
}
