<?php

namespace App\Modules\Reservas\Services;

use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;

/**
 * Cancelar una reserva viva por decisión de la administración: libera el horario, anula lo cobrado que nadie
 * pagó y avisa al residente con el motivo.
 */
final class CancelacionesDeReserva
{
    public function __construct(private readonly CargosDeReservaAction $cargos, private readonly AvisosDeReserva $avisos) {}

    public function cancelar(Reserva $reserva, string $motivo): void
    {
        $reserva->forceFill(['estado' => Reserva::CANCELADA, 'cancelada_en' => now(), 'motivo_cancelacion' => $motivo])->save();
        $this->cargos->anularSinPagar($reserva->cuotaIds());
        $this->avisos->avisar($reserva, 'Tu reserva fue cancelada por la administración', "Motivo: {$motivo}");
    }
}
