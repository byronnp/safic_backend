<?php

namespace App\Modules\Reservas\Services;

use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lo que vence solo (corre cada 15 minutos, por condominio): las solicitudes sin respuesta en 48 horas (o cuyo
 * horario ya empezó) se dan por vencidas y liberan el horario; las reservas confirmadas con el cobro sin pagar
 * al llegar su límite se cancelan y se anula lo cobrado que nadie pagó.
 */
final class VencimientosDeReservas
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly CargosDeReservaAction $cargos,
        private readonly AvisosDeReserva $avisos,
    ) {}

    /** @return array{vencidas: int, canceladas: int} */
    public function procesar(): array
    {
        $zona = $this->calendario->zonaHoraria();
        $ahora = CarbonImmutable::now($zona);

        $vencidas = 0;
        foreach (Reserva::query()->where('estado', Reserva::PENDIENTE)->get() as $r) {
            $empieza = CarbonImmutable::parse($r->fecha->toDateString().' '.$r->inicio, $zona);
            if ($r->created_at->addHours(Reserva::HORAS_PARA_RESPONDER) <= $ahora || $empieza <= $ahora) {
                if ($this->cerrar($r, Reserva::VENCIDA, ['motivo_rechazo' => 'Sin respuesta de la administración'], [Reserva::PENDIENTE])) {
                    $this->avisos->avisar($r, 'Tu solicitud de reserva venció', 'La administración no respondió a tiempo y el horario quedó libre. Puedes volver a solicitarlo.');
                    $vencidas++;
                }
            }
        }

        $canceladas = 0;
        foreach (Reserva::query()->where('estado', Reserva::CONFIRMADA)->whereNotNull('limite_pago_en')->where('limite_pago_en', '<=', $ahora->setTimezone((string) config('app.timezone')))->get() as $r) {
            $empieza = CarbonImmutable::parse($r->fecha->toDateString().' '.$r->inicio, $zona);
            if ($empieza > $ahora && $this->cargos->saldoCentavos($r->cuotaIds()) > 0) {
                if ($this->cerrar($r, Reserva::CANCELADA, ['cancelada_en' => now(), 'motivo_cancelacion' => 'Sin pago a tiempo'], [Reserva::CONFIRMADA])) {
                    $this->cargos->anularSinPagar($r->cuotaIds());
                    $this->avisos->avisar($r, 'Tu reserva se canceló por falta de pago', 'No recibimos el pago a tiempo, así que la reserva se canceló y el horario quedó libre.');
                    $canceladas++;
                }
            }
        }

        return ['vencidas' => $vencidas, 'canceladas' => $canceladas];
    }

    /**
     * Cambia el estado solo si la reserva sigue como la leímos (otra persona pudo resolverla en el medio).
     *
     * @param  array<string, mixed>  $cambios
     * @param  list<string>  $desde
     */
    private function cerrar(Reserva $r, string $estado, array $cambios, array $desde): bool
    {
        return DB::transaction(function () use ($r, $estado, $cambios, $desde): bool {
            $actual = Reserva::query()->lockForUpdate()->find($r->id);
            if ($actual === null || ! in_array($actual->estado, $desde, true)) {
                return false;
            }
            $actual->forceFill(['estado' => $estado] + $cambios)->save();
            $r->setRawAttributes($actual->getAttributes());

            return true;
        });
    }
}
