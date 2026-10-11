<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Unidades\Actions\UnidadesACobrarAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La lista de verificación del cierre de un mes. Cada punto es `listo`, `revisar` (se puede cerrar,
 * pero conviene mirarlo) o `bloquea` (no se puede cerrar). Los puntos de la conciliación bancaria
 * se suman aquí cuando existe ese módulo.
 */
final class CierreMes
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesACobrarAction $aCobrar,
    ) {}

    /**
     * @return list<array{clave: string, titulo: string, detalle: string, estado: string}>
     */
    public function verificaciones(CarbonImmutable $mes): array
    {
        $fin = $mes->endOfMonth()->toDateString();
        $inicio = $mes->toDateString();

        $emitido = PeriodoFinanciero::query()->where('periodo', $inicio)->exists();
        $cuotas = Cuota::query()->where('periodo', $inicio)->where('concepto', Cuota::ORDINARIA)->count();
        $unidades = count($this->aCobrar->execute()['unidades']);

        $pagosPendientes = DB::table('pagos')->where('estado', 'pendiente')->where('fecha_pago', '<=', $fin)->count();

        $gastos = DB::table('gastos')->where('fecha_emision', '<=', $fin);
        $porAprobar = (clone $gastos)->where('estado', 'por_aprobar')->count();
        $sinPagar = (clone $gastos)->where('estado', 'aprobada')->whereColumn('pagado', '<', 'total')->count();

        $terminado = $this->calendario->hoy() > $fin;

        return [
            [
                'clave' => 'mes_terminado', 'titulo' => 'El mes ya terminó',
                'detalle' => $terminado ? 'Todos los movimientos del mes ya tienen fecha.' : 'Se cierra desde el primer día del mes siguiente.',
                'estado' => $terminado ? 'listo' : 'bloquea',
            ],
            [
                'clave' => 'cuotas', 'titulo' => 'Cuotas del mes generadas',
                'detalle' => $emitido ? "{$cuotas} de {$unidades} unidades" : 'Las cuotas de este mes no se han emitido.',
                'estado' => $emitido && $cuotas >= $unidades ? 'listo' : 'bloquea',
            ],
            [
                'clave' => 'pagos', 'titulo' => 'Pagos de residentes revisados',
                'detalle' => $pagosPendientes === 0 ? '0 comprobantes por aprobar' : "{$pagosPendientes} ".($pagosPendientes === 1 ? 'comprobante por aprobar' : 'comprobantes por aprobar'),
                'estado' => $pagosPendientes === 0 ? 'listo' : 'bloquea',
            ],
            [
                'clave' => 'facturas_por_aprobar', 'titulo' => 'Facturas del mes aprobadas',
                'detalle' => $porAprobar === 0 ? 'Ninguna factura espera aprobación.' : "{$porAprobar} ".($porAprobar === 1 ? 'factura sigue' : 'facturas siguen').' por aprobar (pasan al mes siguiente)',
                'estado' => $porAprobar === 0 ? 'listo' : 'revisar',
            ],
            [
                'clave' => 'facturas_sin_pagar', 'titulo' => 'Facturas del mes con pago registrado',
                'detalle' => $sinPagar === 0 ? 'Todas las aprobadas tienen su pago.' : "{$sinPagar} ".($sinPagar === 1 ? 'aprobada sin pagar' : 'aprobadas sin pagar').' (pasan al mes siguiente)',
                'estado' => $sinPagar === 0 ? 'listo' : 'revisar',
            ],
        ];
    }

    /**
     * Lo emitido, lo cobrado y lo pagado a proveedores del mes, como texto decimal.
     *
     * @return array{emitido: string, cobrado: string, gastos_pagados: string}
     */
    public function resumen(CarbonImmutable $mes): array
    {
        $fila = DB::selectOne(
            "select coalesce(sum(monto), 0)::numeric(14,2) as emitido, coalesce(sum(pagado), 0)::numeric(14,2) as cobrado
             from cuotas where periodo = ? and concepto = 'ordinaria'",
            [$mes->toDateString()],
        );
        $pagado = DB::table('pagos_proveedor')->where('estado', 'registrado')
            ->whereBetween('fecha_pago', [$mes->toDateString(), $mes->endOfMonth()->toDateString()])->sum('monto');

        return ['emitido' => (string) $fila->emitido, 'cobrado' => (string) $fila->cobrado, 'gastos_pagados' => Dinero::texto(Dinero::centavos((string) $pagado))];
    }
}
