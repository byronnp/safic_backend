<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;

/**
 * Consulta para el residente: las reservas de sus unidades. Las próximas primero (la más cercana arriba),
 * luego el historial (la más reciente arriba).
 */
final class ListarMisReservasAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly CargosDeReservaAction $cargos,
    ) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $unidades = collect($this->unidades->execute($user->id))->keyBy('unidad_id');
        $reservas = Reserva::query()->whereIn('unidad_id', $unidades->keys())->orderBy('fecha')->orderBy('inicio')->get();
        $areas = CondominioAmenidad::query()->whereIn('id', $reservas->pluck('amenidad_id'))->get()->keyBy('id');
        $ahora = CarbonImmutable::now($this->calendario->zonaHoraria());

        $filas = $reservas->map(function (Reserva $r) use ($areas, $unidades, $ahora): array {
            $empieza = CarbonImmutable::parse($r->fecha->toDateString().' '.$r->inicio, $ahora->getTimezone());
            $viva = in_array($r->estado, Reserva::VIVAS, true);
            $saldo = $r->estado === Reserva::CONFIRMADA ? $this->cargos->saldoCentavos($r->cuotaIds()) : 0;

            return [
                'id' => $r->id, 'area' => $areas->get($r->amenidad_id)->nombre ?? 'Área', 'unidad' => $unidades->get($r->unidad_id)['codigo'] ?? '',
                'fecha' => $r->fecha->toDateString(), 'inicio' => substr($r->inicio, 0, 5), 'fin' => substr($r->fin, 0, 5),
                'estado' => $r->estado, 'costo' => (string) $r->costo, 'garantia' => (string) $r->garantia,
                'proxima' => $viva && ($empieza >= $ahora || $r->estado === Reserva::EN_USO), 'puede_cancelar' => $viva && $empieza > $ahora,
                'por_pagar' => Dinero::texto($saldo),
                'pagar_antes_de' => $saldo > 0 ? $r->limite_pago_en?->setTimezone($ahora->getTimezone())->format('Y-m-d\TH:i') : null,
                'motivo' => $r->motivo_rechazo ?? $r->motivo_cancelacion,
            ];
        });

        return $filas->where('proxima', true)->values()->merge($filas->where('proxima', false)->sortByDesc(fn (array $f) => $f['fecha'].$f['inicio'])->values())->all();
    }
}
