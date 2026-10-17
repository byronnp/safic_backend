<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\AvisosDeReserva;
use App\Modules\Reservas\Services\CobroDeReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: la administración aprueba una solicitud pendiente. La reserva queda confirmada, se cobran el uso
 * y la garantía en la cuenta de la unidad y se avisa al residente.
 */
final class AprobarReservaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly CobroDeReserva $cobro,
        private readonly AvisosDeReserva $avisos,
    ) {}

    public function execute(User $admin, int $reservaId): Reserva
    {
        $reserva = DB::transaction(function () use ($admin, $reservaId): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            if ($reserva->estado !== Reserva::PENDIENTE) {
                throw new ApiException('RESERVA_NO_PENDIENTE', 'Esta solicitud ya fue resuelta o venció.', 409);
            }
            $empieza = CarbonImmutable::parse($reserva->fecha->toDateString().' '.$reserva->inicio, $this->calendario->zonaHoraria());
            if ($empieza <= CarbonImmutable::now($this->calendario->zonaHoraria())) {
                throw new ApiException('RESERVA_YA_EMPEZO', 'El horario de esta solicitud ya empezó. Recházala.', 409);
            }

            $reserva->forceFill(['estado' => Reserva::CONFIRMADA, 'resuelta_por' => $admin->id, 'resuelta_en' => now()])->save();
            $this->cobro->generar($reserva);

            return $reserva;
        });

        $this->avisos->avisar($reserva, 'Tu reserva fue aprobada', $reserva->cuotaIds() === []
            ? 'Tu horario quedó reservado.'
            : 'Se agregó el cobro a tu estado de cuenta. Págalo antes del '.$reserva->limite_pago_en?->setTimezone($this->calendario->zonaHoraria())->format('d/m/Y H:i').' o la reserva se cancela sola.');

        return $reserva;
    }
}
