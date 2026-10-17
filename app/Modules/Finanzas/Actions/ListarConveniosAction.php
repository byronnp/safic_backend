<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\ConvenioPago;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;

/**
 * Consulta: convenios de pago (los activos primero) con su plan de cuotas y su avance.
 */
final class ListarConveniosAction
{
    public function __construct(
        private readonly MorosidadService $morosidad,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ResponsablesDePagoAction $responsables,
    ) {}

    /** @return list<array<string, mixed>> */
    public function execute(?int $unidadId): array
    {
        $convenios = ConvenioPago::query()->when($unidadId !== null, fn ($q) => $q->where('unidad_id', $unidadId))
            ->orderByRaw("case estado when 'activo' then 0 else 1 end")->orderByDesc('id')->limit(100)->get();

        $ids = $convenios->pluck('unidad_id')->all();
        $codigos = $this->codigos->execute($ids);
        $responsables = $this->responsables->execute($ids);

        return $convenios->map(function (ConvenioPago $c) use ($codigos, $responsables): array {
            $avance = $this->morosidad->avance($c);
            $plan = $this->morosidad->plan($c);

            return [
                'id' => $c->id,
                'unidad_id' => $c->unidad_id,
                'codigo' => $codigos[$c->unidad_id] ?? '—',
                'responsable' => $responsables[$c->unidad_id] ?? null,
                'estado' => $c->estado,
                'motivo_cancelacion' => $c->motivo_cancelacion,
                'monto_total' => (string) $c->monto_total,
                'cuotas' => $c->cuotas,
                'desde' => $c->desde->toDateString(),
                'pagado' => Dinero::texto(min($avance['pagado'], Dinero::centavos((string) $c->monto_total))),
                'cuotas_cubiertas' => $avance['cubiertas'],
                'al_dia' => $c->estado === ConvenioPago::ACTIVO ? $avance['al_dia'] : null,
                'proxima_cuota_el' => $c->estado === ConvenioPago::ACTIVO ? $avance['proxima'] : null,
                'plan' => array_map(fn (array $p): array => ['numero' => $p['numero'], 'vence_el' => $p['vence_el'], 'monto' => Dinero::texto($p['monto'])], $plan),
            ];
        })->all();
    }
}
