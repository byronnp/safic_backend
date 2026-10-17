<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Models\RestriccionMora;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: levantar a mano la restricción de áreas comunes de una unidad (por ejemplo, por un
 * acuerdo de la directiva). Exige motivo y queda auditado. Si la unidad sigue cumpliendo la
 * condición, la tarea horaria puede volver a notificarla.
 */
final class LevantarRestriccionAction
{
    public function execute(User $user, int $unidadId, string $motivo): RestriccionMora
    {
        return DB::transaction(function () use ($user, $unidadId, $motivo): RestriccionMora {
            $r = RestriccionMora::query()->where('unidad_id', $unidadId)->whereIn('estado', [RestriccionMora::NOTIFICADA, RestriccionMora::ACTIVA])->lockForUpdate()->first()
                ?? throw new ApiException('SIN_RESTRICCION', 'Esta unidad no tiene una restricción en curso.', 404);

            $r->forceFill(['estado' => RestriccionMora::LEVANTADA, 'levantada_en' => now(), 'motivo_levantamiento' => $motivo, 'levantada_por' => $user->id])->save();

            return $r;
        });
    }
}
