<?php

namespace App\Modules\Asambleas\Services;

use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Unidades\Models\Persona;

/**
 * Quién vota por cada unidad, verificado en el servidor (Art. 41): el propietario vigente o el representante con poder validado, con la
 * unidad presente en la asamblea y sin mora en la foto congelada al instalar.
 */
final class DerechoAVoto
{
    /**
     * Las unidades por las que esta cuenta puede votar si están presentes: las suyas como propietario (salvo que hayan dado un poder
     * validado) y las que representa por poder.
     *
     * @return list<int>
     */
    public function unidadesDeLaCuenta(User $user, Asamblea $asamblea): array
    {
        $personas = Persona::query()->where('user_id', $user->id)->pluck('id')->all();
        if ($personas === []) {
            return [];
        }
        $conPoder = AsambleaPoder::query()->where('asamblea_id', $asamblea->id)->where('estado', AsambleaPoder::VALIDADO)->get()->keyBy('unidad_id');

        $unidades = [];
        foreach (AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->get() as $u) {
            $poder = $conPoder->get($u->unidad_id);
            $esPropietario = count(array_intersect(array_column($u->propietarios, 'persona_id'), $personas)) > 0;
            if ($poder !== null ? in_array($poder->representante_persona_id, $personas, true) : $esPropietario) {
                $unidades[] = $u->unidad_id;
            }
        }

        return $unidades;
    }

    /**
     * Por qué una unidad no puede votar ahora, o null si puede.
     *
     * @return array{codigo: string, mensaje: string}|null
     */
    public function impedimento(Asamblea $asamblea, int $unidadId): ?array
    {
        $u = AsambleaUnidad::query()->where('asamblea_id', $asamblea->id)->where('unidad_id', $unidadId)->first();
        if ($u === null) {
            return ['codigo' => 'UNIDAD_SIN_DERECHO', 'mensaje' => 'Esa unidad no estaba en el padrón al instalar la asamblea.'];
        }
        if (! $u->puede_votar) {
            return ['codigo' => 'UNIDAD_EN_MORA', 'mensaje' => 'La unidad está en mora: no tiene derecho a voto mientras deba (Art. 39).'];
        }
        $presente = AsambleaAsistencia::query()->presentes()->where('asamblea_id', $asamblea->id)->where('unidad_id', $unidadId)->exists();
        if (! $presente) {
            return ['codigo' => 'UNIDAD_AUSENTE', 'mensaje' => 'La unidad no está presente en la asamblea: registra su asistencia para votar.'];
        }

        return null;
    }
}
