<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\GarantiaDeReserva;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración: registrar que devolvió la garantía de una reserva. El monto lo calcula el sistema (garantía
 * menos lo descontado por daños): no se escribe a mano. El dinero no lo mueve el sistema; este registro evita devolver dos veces
 * y deja el rastro (cuánto, cómo, cuándo y quién lo anotó) para conciliar la salida del banco o de caja.
 */
final class RegistrarDevolucionGarantiaAction
{
    public function __construct(private readonly GarantiaDeReserva $garantias, private readonly Calendario $calendario) {}

    public function execute(User $admin, int $reservaId, string $forma, string $fecha, ?string $referencia): Reserva
    {
        return DB::transaction(function () use ($admin, $reservaId, $forma, $fecha, $referencia): Reserva {
            $r = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            $g = $this->garantias->de($r, $this->garantias->pagada($r));

            if ($g === null || $g['estado'] === GarantiaDeReserva::SIN_DEVOLUCION) {
                throw new ApiException('GARANTIA_NO_APLICA', 'Esta reserva no tiene garantía por devolver.', 409);
            }
            if ($g['estado'] === GarantiaDeReserva::DEVUELTA) {
                throw new ApiException('GARANTIA_YA_DEVUELTA', 'La garantía de esta reserva ya se registró como devuelta.', 409);
            }
            if ($g['estado'] === GarantiaDeReserva::ESPERANDO_DECISION) {
                throw new ApiException('DANOS_SIN_DECIDIR', 'Primero decide qué hacer con los daños reportados: de eso depende cuánto se devuelve.', 409);
            }
            if ($fecha > $this->calendario->hoy()) {
                throw new ApiException('FECHA_FUTURA', 'La fecha de la devolución no puede ser futura.', 422);
            }
            if ($fecha < $r->fecha->toDateString()) {
                throw new ApiException('FECHA_ANTERIOR', 'La devolución no puede ser anterior al uso del área.', 422);
            }
            if ($forma === 'transferencia' && ($referencia === null || $referencia === '')) {
                throw new ApiException('REFERENCIA_REQUERIDA', 'Anota el número de la transferencia.', 422);
            }

            $r->forceFill([
                'garantia_devuelta_monto' => Dinero::texto($g['monto']), 'garantia_devuelta_en' => $fecha, 'garantia_devuelta_forma' => $forma,
                'garantia_devuelta_referencia' => $referencia, 'garantia_devuelta_por' => $admin->id, 'garantia_devuelta_registrada_en' => now(),
            ])->save();

            return $r;
        });
    }
}
