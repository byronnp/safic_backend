<?php

namespace App\Modules\Reservas\Services;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Cobra una reserva confirmada en la cuenta de su unidad: la cuota de uso y, si la hay, la garantía (dos cuotas
 * para poder devolver la garantía por separado). Hay que pagar hasta 24 horas antes de empezar; si la reserva se
 * confirma con menos tiempo, se da 24 horas desde ahora (sin pasar de la hora de inicio).
 */
final class CobroDeReserva
{
    public function __construct(private readonly CargosDeReservaAction $cargos, private readonly Calendario $calendario) {}

    public function generar(Reserva $reserva): void
    {
        $zona = $this->calendario->zonaHoraria();
        $empieza = CarbonImmutable::parse($reserva->fecha->toDateString().' '.$reserva->inicio, $zona);
        $limite = $empieza->subHours(Reserva::HORAS_PARA_PAGAR);
        $minimo = CarbonImmutable::now($zona)->addHours(Reserva::HORAS_PARA_PAGAR);
        $limite = $limite->greaterThan($minimo) ? $limite : ($minimo->lessThan($empieza) ? $minimo : $empieza);

        $nombre = CondominioAmenidad::query()->whereKey($reserva->amenidad_id)->value('nombre') ?? 'Área común';
        $vence = $limite->toDateString();

        if (Dinero::centavos((string) $reserva->costo) > 0) {
            $reserva->cuota_uso_id = $this->cargos->cobrar($reserva->unidad_id, "Uso {$nombre} · reserva {$reserva->id}", (string) $reserva->costo, $vence);
        }
        if (Dinero::centavos((string) $reserva->garantia) > 0) {
            $reserva->cuota_garantia_id = $this->cargos->cobrar($reserva->unidad_id, "Garantía {$nombre} · reserva {$reserva->id}", (string) $reserva->garantia, $vence);
        }
        $reserva->limite_pago_en = $reserva->cuotaIds() === [] ? null : Carbon::instance($limite)->setTimezone((string) config('app.timezone'));
        $reserva->save();
    }
}
