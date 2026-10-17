<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: dar el ingreso a una reserva de hoy. Debe estar confirmada y pagada (o gratuita), y la
 * unidad no puede tener la restricción de áreas comunes por mora.
 */
final class RegistrarIngresoReservaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly CargosDeReservaAction $cargos,
        private readonly EstadoDeUnidadParaAreasAction $mora,
    ) {}

    public function execute(User $guardia, int $reservaId): Reserva
    {
        return DB::transaction(function () use ($guardia, $reservaId): Reserva {
            $reserva = Reserva::query()->lockForUpdate()->findOrFail($reservaId);

            if ($reserva->fecha->toDateString() !== $this->calendario->hoy()) {
                throw new ApiException('RESERVA_NO_ES_DE_HOY', 'Esta reserva no es de hoy.', 409);
            }
            if ($reserva->estado !== Reserva::CONFIRMADA) {
                throw new ApiException('RESERVA_NO_CONFIRMADA', 'Solo se da ingreso a reservas confirmadas.', 409);
            }
            if ($this->cargos->saldoCentavos($reserva->cuotaIds()) > 0) {
                throw new ApiException('RESERVA_SIN_PAGAR', 'La reserva tiene pagos pendientes. Que la unidad pague antes de ingresar.', 409);
            }
            $restringible = CondominioAmenidad::query()->whereKey($reserva->amenidad_id)->value('esencial') === false;
            if ($restringible && $this->mora->restringida($reserva->unidad_id)) {
                throw new ApiException('UNIDAD_RESTRINGIDA', 'La unidad tiene restringido el uso de áreas comunes por mora. No se da el ingreso.', 409);
            }

            $reserva->forceFill(['estado' => Reserva::EN_USO, 'ingreso_en' => now(), 'ingreso_por' => $guardia->id])->save();

            return $reserva;
        });
    }
}
