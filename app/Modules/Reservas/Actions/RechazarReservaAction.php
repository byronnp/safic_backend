<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\AvisosDeReserva;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: la administración rechaza una solicitud pendiente, con un motivo que ve el residente. Libera el horario.
 */
final class RechazarReservaAction
{
    public function __construct(private readonly AvisosDeReserva $avisos) {}

    public function execute(User $admin, int $reservaId, string $motivo): Reserva
    {
        $reserva = DB::transaction(function () use ($admin, $reservaId, $motivo): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            if ($reserva->estado !== Reserva::PENDIENTE) {
                throw new ApiException('RESERVA_NO_PENDIENTE', 'Esta solicitud ya fue resuelta o venció.', 409);
            }
            $reserva->forceFill(['estado' => Reserva::RECHAZADA, 'resuelta_por' => $admin->id, 'resuelta_en' => now(), 'motivo_rechazo' => $motivo])->save();

            return $reserva;
        });

        $this->avisos->avisar($reserva, 'Tu solicitud de reserva fue rechazada', "Motivo: {$motivo}");

        return $reserva;
    }
}
