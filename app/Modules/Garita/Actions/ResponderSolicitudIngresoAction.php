<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Garita\Services\AvisosEnVivoDeGarita;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del residente: permitir o rechazar el ingreso de quien espera en la garita. Gana la primera respuesta de la unidad.
 * Una solicitud de otra unidad se responde como si no existiera.
 */
final class ResponderSolicitudIngresoAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidades, private readonly AvisosEnVivoDeGarita $enVivo) {}

    public function execute(User $user, int $id, bool $permitir): SolicitudIngreso
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        return DB::transaction(function () use ($user, $id, $permitir, $ids): SolicitudIngreso {
            $s = SolicitudIngreso::query()->whereIn('unidad_id', $ids)->lockForUpdate()->findOrFail($id);

            $estado = $s->estadoEfectivo();
            if ($estado === 'vencida') {
                throw new ApiException('SOLICITUD_VENCIDA', 'Pasó el tiempo para responder. El guardia llamará a la unidad.', 409);
            }
            if ($estado !== SolicitudIngreso::PENDIENTE) {
                throw new ApiException('SOLICITUD_YA_RESPONDIDA', 'Esta solicitud ya fue respondida o cancelada.', 409);
            }

            $s->forceFill([
                'estado' => $permitir ? SolicitudIngreso::PERMITIDA : SolicitudIngreso::RECHAZADA, 'respondida_por' => $user->id, 'respondida_en' => now(),
            ])->save();

            // El guardia ve la respuesta y los demás de la unidad ven que ya la respondió alguien
            $this->enVivo->aLaGarita($s->condominio_id, AvisosEnVivoDeGarita::SOLICITUDES, $s->id);
            $this->enVivo->aLaUnidad($s->condominio_id, $s->unidad_id, AvisosEnVivoDeGarita::SOLICITUDES, $s->id);

            return $s;
        });
    }
}
