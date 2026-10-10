<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\SaldoFavorService;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;
use App\Modules\Unidades\Actions\UnidadEstadoCuentaAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de la administración (Estado de cuenta de una unidad): lo que debe, su saldo a favor, los
 * días de atraso y los movimientos —cargos (cuotas) y abonos (pagos aprobados)— con el saldo corriente.
 * El saldo se calcula siempre de cargos y abonos; nunca se edita a mano. Un saldo negativo es a favor.
 */
final class ObtenerEstadoCuentaAction
{
    public function __construct(
        private readonly UnidadEstadoCuentaAction $unidad,
        private readonly ResponsablesDePagoAction $responsables,
        private readonly SaldoFavorService $saldoFavor,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $unidadId, ?string $desde, ?string $hasta): array
    {
        $datos = $this->unidad->execute($unidadId)
            ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'Esa unidad no existe.', 404);
        $hoy = $this->calendario->hoy();
        $desde ??= '0001-01-01';
        $hasta ??= $hoy;

        $todos = $this->movimientos($unidadId);

        // Saldo corriente sobre todo el historial; el rango solo recorta lo que se muestra
        $saldo = 0;
        $saldoInicial = 0;
        $filas = [];
        foreach ($todos as $m) {
            $saldo += $m['cargo'] - $m['abono'];
            if ($m['fecha'] < $desde) {
                $saldoInicial = $saldo;

                continue;
            }
            if ($m['fecha'] > $hasta) {
                continue;
            }
            $filas[] = [
                'fecha' => $m['fecha'],
                'concepto' => $m['concepto'],
                'cargo' => $m['cargo'] > 0 ? Dinero::texto($m['cargo']) : null,
                'abono' => $m['abono'] > 0 ? Dinero::texto($m['abono']) : null,
                'saldo' => Dinero::texto($saldo),
            ];
        }

        $cuotas = Cuota::query()->where('unidad_id', $unidadId)->whereColumn('monto', '>', 'pagado')->orderBy('vence_el')->get();
        $pendiente = Dinero::suma($cuotas->map(fn (Cuota $c) => Dinero::texto(Dinero::centavos($c->monto) - Dinero::centavos($c->pagado))));
        $favor = Dinero::centavos($this->saldoFavor->de($unidadId));
        $vencida = $cuotas->first(fn (Cuota $c) => $c->vence_el->toDateString() < $hoy);
        $siguiente = $cuotas->first(fn (Cuota $c) => $c->vence_el->toDateString() >= $hoy);

        $anio = CarbonImmutable::parse($hoy)->format('Y');
        $pagado = DB::selectOne(
            "select coalesce(sum(monto),0)::numeric(14,2) as total, count(*) as pagos from pagos where unidad_id = ? and estado = 'aprobado' and extract(year from fecha_pago) = ?",
            [$unidadId, (int) $anio],
        );

        return [
            'unidad' => $datos,
            'responsable' => $this->responsables->execute([$unidadId])[$unidadId] ?? null,
            'saldo_pendiente' => Dinero::texto(max(0, $pendiente - $favor)),
            'saldo_favor' => Dinero::texto($favor),
            'dias_atraso' => $vencida === null ? 0 : (int) CarbonImmutable::parse($vencida->vence_el->toDateString())->diffInDays(CarbonImmutable::parse($hoy)),
            'proximo_vencimiento' => $siguiente?->vence_el->toDateString(),
            'pagado_en_el_anio' => ['monto' => (string) $pagado->total, 'pagos' => (int) $pagado->pagos, 'anio' => (int) $anio],
            'saldo_inicial' => Dinero::texto($saldoInicial),
            'movimientos' => $filas,
        ];
    }

    /**
     * Cargos y abonos de la unidad en orden cronológico (a igual fecha, primero los cargos).
     *
     * @return list<array{fecha: string, concepto: string, cargo: int, abono: int, orden: int}>
     */
    private function movimientos(int $unidadId): array
    {
        $m = [];

        foreach (Cuota::query()->where('unidad_id', $unidadId)->orderBy('periodo')->orderBy('id')->get() as $c) {
            $mes = $c->periodo->format('m/Y');
            $m[] = [
                // La ordinaria se cobra el día 1 del mes; la extraordinaria, cuando se creó
                'fecha' => $c->concepto === 'extraordinaria' ? $c->created_at->timezone('America/Guayaquil')->toDateString() : $c->periodo->toDateString(),
                'concepto' => $c->concepto === 'extraordinaria' ? 'Cuota extraordinaria · '.$c->detalle : 'Cuota ordinaria '.$mes,
                'cargo' => Dinero::centavos($c->monto),
                'abono' => 0,
                'orden' => 0,
            ];
        }

        $ajustes = DB::table('pago_aplicaciones')->selectRaw('pago_id, sum(ajuste)::numeric(14,2) as ajuste')->groupBy('pago_id')->pluck('ajuste', 'pago_id');
        foreach (Pago::query()->where('unidad_id', $unidadId)->where('estado', Pago::APROBADO)->orderBy('fecha_pago')->orderBy('id')->get() as $p) {
            $m[] = [
                'fecha' => $p->fecha_pago->toDateString(),
                'concepto' => $p->metodo === 'efectivo'
                    ? 'Pago en efectivo · recibo '.ListarPagosAction::numeroRecibo((int) $p->recibo_numero)
                    : 'Pago transferencia'.($p->numero_comprobante ? ' · REF '.$p->numero_comprobante : ''),
                'cargo' => 0,
                'abono' => Dinero::centavos($p->monto),
                'orden' => 1,
            ];
            $ajuste = Dinero::centavos((string) ($ajustes[$p->id] ?? '0'));
            if ($ajuste > 0) {
                $m[] = ['fecha' => $p->fecha_pago->toDateString(), 'concepto' => 'Ajuste por tolerancia bancaria', 'cargo' => 0, 'abono' => $ajuste, 'orden' => 2];
            }
        }

        usort($m, fn (array $a, array $b) => [$a['fecha'], $a['orden']] <=> [$b['fecha'], $b['orden']]);

        return $m;
    }
}
