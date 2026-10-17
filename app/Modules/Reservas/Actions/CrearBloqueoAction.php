<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\BloqueoArea;
use App\Modules\Reservas\Services\BloqueosDeArea;
use App\Modules\Reservas\Services\CancelacionesDeReserva;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: la administración bloquea un área un día completo o unas horas. Si ya hay reservas encima no se
 * bloquea a ciegas: se responde con cuántas son (409) y, si la administración lo confirma, se cancelan y se
 * avisa a cada residente con el motivo del bloqueo.
 */
final class CrearBloqueoAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly BloqueosDeArea $bloqueos,
        private readonly CancelacionesDeReserva $cancelaciones,
    ) {}

    /** @return array{bloqueo: BloqueoArea, canceladas: int} */
    public function execute(User $admin, int $amenidadId, string $fecha, ?string $inicio, ?string $fin, string $motivo, bool $cancelarReservas): array
    {
        if ($fecha < $this->calendario->hoy()) {
            throw new ApiException('FECHA_PASADA', 'No se puede bloquear un día que ya pasó.', 422);
        }
        if (($inicio === null) !== ($fin === null)) {
            throw new ApiException('HORAS_INCOMPLETAS', 'Indica la hora de inicio y la de fin, o ninguna para bloquear todo el día.', 422);
        }
        if ($inicio !== null && $fin !== null && $inicio >= $fin) {
            throw new ApiException('HORAS_INVERTIDAS', 'La hora de fin debe ser posterior a la de inicio.', 422);
        }

        return DB::transaction(function () use ($admin, $amenidadId, $fecha, $inicio, $fin, $motivo, $cancelarReservas): array {
            $area = CondominioAmenidad::query()->where('reservable', true)->lockForUpdate()->findOrFail($amenidadId);
            $bloqueo = BloqueoArea::create(['amenidad_id' => $area->id, 'fecha' => $fecha, 'inicio' => $inicio, 'fin' => $fin, 'motivo' => $motivo, 'creado_por' => $admin->id]);

            $afectadas = $this->bloqueos->reservasAfectadas($bloqueo);
            if ($afectadas->isNotEmpty() && ! $cancelarReservas) {
                throw new ApiException('HAY_RESERVAS_AFECTADAS', "Hay {$afectadas->count()} reserva(s) en ese horario. Confirma para cancelarlas y avisarles.", 409, ['reservas' => $afectadas->count()]);
            }
            foreach ($afectadas as $reserva) {
                $this->cancelaciones->cancelar($reserva, "El área se bloqueó: {$motivo}");
            }

            return ['bloqueo' => $bloqueo, 'canceladas' => $afectadas->count()];
        });
    }
}
