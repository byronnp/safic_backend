<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consulta: resumen financiero de un mes. Lo esperado y lo recaudado son del mes; la
 * cartera (lo vencido y su antigüedad) es de todas las cuotas con saldo, contada a hoy
 * en la hora del condominio. Todo el dinero se suma en PostgreSQL como numeric y viaja como
 * texto. Los gastos, la conciliación y los pagos recientes llegan con sus propios módulos.
 */
final class ObtenerResumenFinancieroAction
{
    /** Tramos de antigüedad: clave, etiqueta, días de atraso desde y hasta. */
    private const TRAMOS = [
        ['al_dia', 'Al día', null, 0],
        ['d1_30', '1–30 días', 1, 30],
        ['d31_60', '31–60 días', 31, 60],
        ['d61_90', '61–90 días', 61, 90],
        ['d90_mas', 'Más de 90', 91, null],
    ];

    public function __construct(private readonly Calendario $calendario) {}

    /**
     * @param  string  $periodo  YYYY-MM
     * @return array<string, mixed>
     */
    public function execute(string $periodo): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo)->toDateString();
        $hoy = $this->calendario->hoy();

        $emitido = PeriodoFinanciero::query()->where('periodo', $mes)->first();

        $mensual = DB::selectOne(
            "select coalesce(sum(monto), 0)::numeric(14,2) as esperado, coalesce(sum(pagado), 0)::numeric(14,2) as recaudado,
                    count(*) as cuotas
             from cuotas where periodo = ? and concepto = 'ordinaria'",
            [$mes],
        );

        $filas = DB::select(
            "select case
                      when vence_el >= ?::date then 'al_dia'
                      when (?::date - vence_el) <= 30 then 'd1_30'
                      when (?::date - vence_el) <= 60 then 'd31_60'
                      when (?::date - vence_el) <= 90 then 'd61_90'
                      else 'd90_mas' end as tramo,
                    sum(monto - pagado)::numeric(14,2) as saldo,
                    count(distinct unidad_id) as unidades
             from cuotas where monto > pagado group by 1",
            [$hoy, $hoy, $hoy, $hoy],
        );
        $porTramo = collect($filas)->keyBy('tramo');

        $antiguedad = array_map(fn (array $t) => [
            'tramo' => $t[0],
            'etiqueta' => $t[1],
            'saldo' => (string) ($porTramo[$t[0]]->saldo ?? '0.00'),
            'unidades' => (int) ($porTramo[$t[0]]->unidades ?? 0),
        ], self::TRAMOS);

        $vencida = DB::selectOne(
            'select coalesce(sum(monto - pagado), 0)::numeric(14,2) as saldo, count(distinct unidad_id) as unidades
             from cuotas where monto > pagado and vence_el < ?::date',
            [$hoy],
        );

        $config = ConfiguracionCobro::query()->first();

        return [
            'periodo' => $periodo,
            'emitido' => $emitido !== null,
            'estado' => $emitido?->estado,
            'esperado' => (string) $mensual->esperado,
            'recaudado' => (string) $mensual->recaudado,
            'cuotas' => (int) $mensual->cuotas,
            'cartera_vencida' => ['saldo' => (string) $vencida->saldo, 'unidades' => (int) $vencida->unidades],
            'antiguedad' => $antiguedad,
            'cobro' => $config === null ? null : [
                'metodo' => $config->metodo,
                'dia_vencimiento' => $config->dia_vencimiento,
            ],
        ];
    }
}
