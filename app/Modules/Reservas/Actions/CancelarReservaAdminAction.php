<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\CancelacionesDeReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: la administración cancela una reserva futura (pendiente o confirmada) con un motivo que ve el residente.
 */
final class CancelarReservaAdminAction
{
    public function __construct(private readonly Calendario $calendario, private readonly CancelacionesDeReserva $cancelaciones) {}

    public function execute(int $reservaId, string $motivo): Reserva
    {
        return DB::transaction(function () use ($reservaId, $motivo): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            $empieza = CarbonImmutable::parse($reserva->fecha->toDateString().' '.$reserva->inicio, $this->calendario->zonaHoraria());
            if (! in_array($reserva->estado, Reserva::VIVAS, true) || $empieza <= CarbonImmutable::now($this->calendario->zonaHoraria())) {
                throw new ApiException('RESERVA_NO_CANCELABLE', 'Esta reserva ya no se puede cancelar.', 409);
            }
            $this->cancelaciones->cancelar($reserva, $motivo);

            return $reserva;
        });
    }
}
