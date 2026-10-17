<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta del guardia: las reservas de hoy listas para recibir gente. Solo llegan las pagadas o gratuitas
 * (el guardia no recibe pagos) y no las canceladas ni pendientes.
 */
final class ReservasDeHoyAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly CargosDeReservaAction $cargos,
        private readonly EstadoDeUnidadParaAreasAction $mora,
    ) {}

    /** @return array{fecha: string, reservas: list<array<string, mixed>>} */
    public function execute(): array
    {
        $hoy = $this->calendario->hoy();
        $reservas = Reserva::query()->whereDate('fecha', $hoy)->whereIn('estado', [Reserva::CONFIRMADA, Reserva::EN_USO, Reserva::FINALIZADA])
            ->orderBy('inicio')->orderBy('id')->get()
            ->filter(fn (Reserva $r): bool => $this->cargos->saldoCentavos($r->cuotaIds()) === 0)->values();

        $areas = CondominioAmenidad::query()->whereIn('id', $reservas->pluck('amenidad_id'))->get()->keyBy('id');
        $unidades = Unidad::query()->whereIn('id', $reservas->pluck('unidad_id'))->get()->keyBy('id');
        $usuarios = User::query()->whereIn('id', $reservas->pluck('solicitante_id')->filter())->get()->keyBy('id');

        return [
            'fecha' => $hoy,
            'reservas' => $reservas->map(function (Reserva $r) use ($areas, $unidades, $usuarios): array {
                $area = $areas->get($r->amenidad_id);

                return [
                    'id' => $r->id, 'area' => $area->nombre ?? 'Área', 'unidad' => $unidades->get($r->unidad_id)->codigo ?? '',
                    'persona' => $usuarios->get($r->solicitante_id)->name ?? null,
                    'inicio' => substr($r->inicio, 0, 5), 'fin' => substr($r->fin, 0, 5), 'estado' => $r->estado,
                    'costo' => (string) $r->costo, 'garantia' => (string) $r->garantia,
                    'restringida' => $area !== null && ! $area->esencial && $this->mora->restringida($r->unidad_id),
                ];
            })->all(),
        ];
    }
}
