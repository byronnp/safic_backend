<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el residente cancela una reserva futura de una de sus unidades. Libera el horario al instante.
 */
final class CancelarMiReservaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly CargosDeReservaAction $cargos,
    ) {}

    public function execute(User $user, int $reservaId, ?string $motivo): Reserva
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        return DB::transaction(function () use ($ids, $reservaId, $motivo): Reserva {
            // Una reserva de otra unidad se responde como si no existiera
            $reserva = Reserva::query()->whereIn('unidad_id', $ids)->lockForUpdate()->findOrFail($reservaId);
            $empieza = CarbonImmutable::parse($reserva->fecha->toDateString().' '.$reserva->inicio, $this->calendario->zonaHoraria());

            if (! in_array($reserva->estado, Reserva::VIVAS, true) || $empieza <= CarbonImmutable::now($this->calendario->zonaHoraria())) {
                throw new ApiException('RESERVA_NO_CANCELABLE', 'Esta reserva ya no se puede cancelar.', 409);
            }

            $reserva->forceFill(['estado' => Reserva::CANCELADA, 'cancelada_en' => now(), 'motivo_cancelacion' => $motivo])->save();
            // Lo cobrado que nadie pagó se anula; lo ya pagado lo resuelve la administración
            $this->cargos->anularSinPagar($reserva->cuotaIds());

            return $reserva;
        });
    }
}
