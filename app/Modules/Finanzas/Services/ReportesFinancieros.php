<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Los reportes financieros del condominio. Cada uno devuelve la misma forma: título, columnas
 * (clave, etiqueta y tipo: texto, dinero, entero o porcentaje) y filas con esas claves. Los montos
 * son texto decimal. Ningún reporte trae cédula, teléfono ni correo: solo el nombre del responsable
 * de pago donde hace falta, así que el contador exporta lo mismo que ve en pantalla.
 */
final class ReportesFinancieros
{
    public const REPORTES = [
        'ingresos_gastos' => 'Ingresos vs. gastos',
        'cartera_antiguedad' => 'Cartera por antigüedad',
        'recaudacion_mensual' => 'Recaudación mensual',
        'conciliaciones' => 'Conciliaciones',
        'gastos_proveedor' => 'Gastos por proveedor',
        'estados_cuenta' => 'Estados de cuenta',
    ];

    /** Meses que muestra la recaudación mensual, terminando en el elegido. */
    private const MESES_RECAUDACION = 12;

    public function __construct(
        private readonly Calendario $calendario,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ResponsablesDePagoAction $responsables,
    ) {}

    /**
     * @return array{reporte: string, titulo: string, periodo: string, columnas: list<array{clave: string, etiqueta: string, tipo: string}>, filas: list<array<string, mixed>>}
     */
    public function generar(string $reporte, string $periodo): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);
        $nombre = self::REPORTES[$reporte] ?? throw new ApiException('REPORTE_DESCONOCIDO', 'Ese reporte no existe.', 404);

        [$columnas, $filas] = match ($reporte) {
            'ingresos_gastos' => $this->ingresosGastos($mes),
            'cartera_antiguedad' => $this->cartera(),
            'recaudacion_mensual' => $this->recaudacion($mes),
            'conciliaciones' => $this->conciliaciones($mes),
            'gastos_proveedor' => $this->gastosProveedor($mes),
            default => $this->estadosDeCuenta(),
        };

        $mesTexto = $mes->locale('es')->translatedFormat('M Y');
        $conPeriodo = in_array($reporte, ['ingresos_gastos', 'recaudacion_mensual', 'conciliaciones', 'gastos_proveedor'], true);

        return [
            'reporte' => $reporte,
            'titulo' => $nombre.($conPeriodo ? " · {$mesTexto}" : ' · a '.CarbonImmutable::parse($this->calendario->hoy())->locale('es')->translatedFormat('j M Y')),
            'periodo' => $periodo,
            'columnas' => $columnas,
            'filas' => $filas,
        ];
    }

    private static function col(string $clave, string $etiqueta, string $tipo = 'texto'): array
    {
        return ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => $tipo];
    }

    private static function dinero(mixed $valor): string
    {
        return Dinero::texto(Dinero::centavos((string) ($valor ?? 0)));
    }

    /** «Banco Pichincha ····4821». El número va cifrado en la base: se lee por el modelo, que lo descifra. */
    private function nombreCuenta(int $id): string
    {
        $c = CuentaBancaria::query()->find($id);

        return $c === null ? 'Cuenta' : "{$c->banco} ····".substr((string) $c->numero, -4);
    }

    // ---------- Ingresos vs. gastos ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function ingresosGastos(CarbonImmutable $mes): array
    {
        $ini = $mes->toDateString();
        $fin = $mes->endOfMonth()->toDateString();
        $filas = [];
        $fila = function (string $seccion, string $concepto, int $centavos) use (&$filas): void {
            $filas[] = ['seccion' => $seccion, 'concepto' => $concepto, 'monto' => Dinero::texto($centavos)];
        };

        $porMetodo = DB::table('pagos')->where('estado', 'aprobado')->whereBetween('fecha_pago', [$ini, $fin])->groupBy('metodo')->selectRaw('metodo, sum(monto) as total')->pluck('total', 'metodo');
        $transferencias = Dinero::centavos((string) ($porMetodo['transferencia'] ?? 0));
        $efectivo = Dinero::centavos((string) ($porMetodo['efectivo'] ?? 0));
        $intereses = Dinero::centavos((string) DB::table('movimientos_bancarios')->where('tipo', 'interes')->whereIn('estado', ['automatico', 'conciliado'])->whereBetween('fecha', [$ini, $fin])->sum('monto'));

        $fila('Ingresos', 'Cuotas cobradas por transferencia', $transferencias);
        $fila('Ingresos', 'Cuotas cobradas en efectivo', $efectivo);
        $fila('Ingresos', 'Intereses bancarios', $intereses);
        $ingresos = $transferencias + $efectivo + $intereses;
        $fila('Ingresos', 'Total ingresos', $ingresos);

        $gastos = 0;
        $categorias = DB::table('pago_proveedor_aplicaciones as a')
            ->join('pagos_proveedor as p', 'p.id', '=', 'a.pago_proveedor_id')->join('gastos as g', 'g.id', '=', 'a.gasto_id')
            ->where('p.estado', 'registrado')->whereBetween('p.fecha_pago', [$ini, $fin])
            ->groupByRaw("coalesce(g.categoria, 'Sin categoría')")->orderByRaw("coalesce(g.categoria, 'Sin categoría')")
            ->selectRaw("coalesce(g.categoria, 'Sin categoría') as categoria, sum(a.monto) as total")->get();
        foreach ($categorias as $c) {
            $monto = Dinero::centavos((string) $c->total);
            $fila('Gastos', (string) $c->categoria, $monto);
            $gastos += $monto;
        }
        $comisiones = abs(Dinero::centavos((string) DB::table('movimientos_bancarios')->where('tipo', 'comision')->whereIn('estado', ['automatico', 'conciliado'])->whereBetween('fecha', [$ini, $fin])->sum('monto')));
        $fila('Gastos', 'Comisiones bancarias', $comisiones);
        $gastos += $comisiones;
        $fila('Gastos', 'Total gastos', $gastos);

        $fila('Resultado', 'Resultado del mes (ingresos − gastos)', $ingresos - $gastos);

        $saldos = DB::table('estados_cuenta_bancarios')->where('periodo', $ini)->whereNotNull('saldo_final')->orderBy('cuenta_bancaria_id')->get(['cuenta_bancaria_id', 'saldo_final']);
        foreach ($saldos as $s) {
            $filas[] = ['seccion' => 'Saldo en bancos', 'concepto' => $this->nombreCuenta((int) $s->cuenta_bancaria_id), 'monto' => self::dinero($s->saldo_final)];
        }

        return [[self::col('seccion', 'Sección'), self::col('concepto', 'Concepto'), self::col('monto', 'Monto', 'dinero')], $filas];
    }

    // ---------- Cartera por antigüedad ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function cartera(): array
    {
        $hoy = $this->calendario->hoy();
        $datos = DB::table('cuotas')->whereColumn('monto', '>', 'pagado')->groupBy('unidad_id')->selectRaw(
            'unidad_id,
             sum(case when vence_el >= ?::date then monto - pagado else 0 end) as al_dia,
             sum(case when vence_el < ?::date and (?::date - vence_el) <= 30 then monto - pagado else 0 end) as d1_30,
             sum(case when (?::date - vence_el) between 31 and 60 then monto - pagado else 0 end) as d31_60,
             sum(case when (?::date - vence_el) between 61 and 90 then monto - pagado else 0 end) as d61_90,
             sum(case when (?::date - vence_el) > 90 then monto - pagado else 0 end) as d90_mas,
             sum(monto - pagado) as total',
            [$hoy, $hoy, $hoy, $hoy, $hoy, $hoy],
        )->get();

        $ids = $datos->pluck('unidad_id')->map(fn ($i) => (int) $i)->all();
        $codigos = $this->codigos->execute($ids);
        $responsables = $this->responsables->execute($ids);

        $filas = $datos->map(fn (object $d): array => [
            'unidad' => $codigos[(int) $d->unidad_id] ?? '—',
            'responsable' => $responsables[(int) $d->unidad_id]['nombre'] ?? 'Sin responsable',
            'al_dia' => self::dinero($d->al_dia), 'd1_30' => self::dinero($d->d1_30), 'd31_60' => self::dinero($d->d31_60),
            'd61_90' => self::dinero($d->d61_90), 'd90_mas' => self::dinero($d->d90_mas), 'total' => self::dinero($d->total),
        ])->sortBy('unidad', SORT_NATURAL)->values()->all();

        return [[
            self::col('unidad', 'Unidad'), self::col('responsable', 'Responsable'), self::col('al_dia', 'Al día', 'dinero'), self::col('d1_30', '1–30 días', 'dinero'),
            self::col('d31_60', '31–60 días', 'dinero'), self::col('d61_90', '61–90 días', 'dinero'), self::col('d90_mas', 'Más de 90', 'dinero'), self::col('total', 'Total', 'dinero'),
        ], $filas];
    }

    // ---------- Recaudación mensual ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function recaudacion(CarbonImmutable $mes): array
    {
        $desde = $mes->subMonths(self::MESES_RECAUDACION - 1)->toDateString();
        $datos = DB::table('cuotas')->whereBetween('periodo', [$desde, $mes->toDateString()])->groupBy('periodo')->orderBy('periodo')
            ->selectRaw('periodo, sum(monto) as emitido, sum(pagado) as cobrado')->get();

        $filas = $datos->map(function (object $d): array {
            $emitido = Dinero::centavos((string) $d->emitido);
            $cobrado = Dinero::centavos((string) $d->cobrado);

            return [
                'mes' => CarbonImmutable::parse((string) $d->periodo)->locale('es')->translatedFormat('F Y'),
                'emitido' => Dinero::texto($emitido), 'cobrado' => Dinero::texto($cobrado),
                // Entero hacia abajo: 100 solo cuando se cobró todo
                'porcentaje' => $emitido > 0 ? min(100, intdiv($cobrado * 100, $emitido)) : 0,
            ];
        })->all();

        return [[self::col('mes', 'Mes'), self::col('emitido', 'Emitido', 'dinero'), self::col('cobrado', 'Cobrado', 'dinero'), self::col('porcentaje', '% de cobro', 'porcentaje')], $filas];
    }

    // ---------- Conciliaciones ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function conciliaciones(CarbonImmutable $mes): array
    {
        $estados = DB::table('estados_cuenta_bancarios')->where('periodo', $mes->toDateString())->orderBy('cuenta_bancaria_id')->get(['id', 'cuenta_bancaria_id']);

        $filas = [];
        foreach ($estados as $e) {
            $porEstado = DB::table('movimientos_bancarios')->where('estado_cuenta_id', $e->id)->groupBy('estado')->selectRaw('estado, count(*) as n, sum(monto) as total')->get()->keyBy('estado');
            $n = fn (string $estado): int => (int) ($porEstado[$estado]->n ?? 0);
            $pendiente = Dinero::centavos((string) ($porEstado['sugerido']->total ?? 0)) + Dinero::centavos((string) ($porEstado['sin_identificar']->total ?? 0));

            $filas[] = [
                'cuenta' => $this->nombreCuenta((int) $e->cuenta_bancaria_id),
                'movimientos' => array_sum(array_map(fn ($x) => (int) $x->n, $porEstado->all())),
                'conciliados' => $n('automatico') + $n('conciliado'),
                'sugeridos' => $n('sugerido'),
                'sin_identificar' => $n('sin_identificar'),
                'monto_pendiente' => Dinero::texto($pendiente),
            ];
        }

        return [[
            self::col('cuenta', 'Cuenta'), self::col('movimientos', 'Movimientos', 'entero'), self::col('conciliados', 'Conciliados', 'entero'),
            self::col('sugeridos', 'Sugeridos', 'entero'), self::col('sin_identificar', 'Sin identificar', 'entero'), self::col('monto_pendiente', 'Monto pendiente', 'dinero'),
        ], $filas];
    }

    // ---------- Gastos por proveedor ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function gastosProveedor(CarbonImmutable $mes): array
    {
        $ini = $mes->toDateString();
        $fin = $mes->endOfMonth()->toDateString();

        $proveedores = DB::table('proveedores')->orderBy('razon_social')->get(['id', 'razon_social', 'ruc']);
        $facturado = DB::table('gastos')->whereIn('estado', ['aprobada', 'pagada'])->whereBetween('fecha_emision', [$ini, $fin])->groupBy('proveedor_id')->selectRaw('proveedor_id, count(*) as n, sum(total) as total')->get()->keyBy('proveedor_id');
        $pagado = DB::table('pagos_proveedor')->where('estado', 'registrado')->whereBetween('fecha_pago', [$ini, $fin])->groupBy('proveedor_id')->selectRaw('proveedor_id, count(*) as n, sum(monto) as total')->get()->keyBy('proveedor_id');
        $saldo = DB::table('gastos')->where('estado', 'aprobada')->groupBy('proveedor_id')->selectRaw('proveedor_id, sum(total - pagado) as total')->pluck('total', 'proveedor_id');

        $filas = [];
        foreach ($proveedores as $p) {
            $f = $facturado[$p->id] ?? null;
            $g = $pagado[$p->id] ?? null;
            $porPagar = $saldo[$p->id] ?? 0;
            if ($f === null && $g === null && Dinero::centavos((string) $porPagar) === 0) {
                continue;
            }

            $filas[] = [
                'proveedor' => $p->razon_social, 'ruc' => $p->ruc,
                'facturas' => (int) ($f->n ?? 0), 'facturado' => self::dinero($f->total ?? 0),
                'pagos' => (int) ($g->n ?? 0), 'pagado' => self::dinero($g->total ?? 0),
                'saldo_por_pagar' => self::dinero($porPagar),
            ];
        }

        return [[
            self::col('proveedor', 'Proveedor'), self::col('ruc', 'RUC'), self::col('facturas', 'Facturas', 'entero'), self::col('facturado', 'Facturado', 'dinero'),
            self::col('pagos', 'Pagos', 'entero'), self::col('pagado', 'Pagado en el mes', 'dinero'), self::col('saldo_por_pagar', 'Saldo por pagar (total)', 'dinero'),
        ], $filas];
    }

    // ---------- Estados de cuenta (todas las unidades) ----------

    /** @return array{0: list<array<string, string>>, 1: list<array<string, mixed>>} */
    private function estadosDeCuenta(): array
    {
        $hoy = $this->calendario->hoy();
        $unidades = DB::table('unidades')->whereNull('deleted_at')->where('cuota_mensual', '>', 0)->get(['id', 'codigo']);
        $ids = $unidades->pluck('id')->map(fn ($i) => (int) $i)->all();

        $debe = DB::table('cuotas')->whereColumn('monto', '>', 'pagado')->groupBy('unidad_id')
            ->selectRaw('unidad_id, sum(monto - pagado) as total, sum(case when vence_el < ?::date then monto - pagado else 0 end) as vencido, min(case when vence_el < ?::date then vence_el end) as primera', [$hoy, $hoy])->get()->keyBy('unidad_id');
        $favor = DB::table('pagos')->where('estado', 'aprobado')->groupBy('unidad_id')->selectRaw('unidad_id, sum(monto) as total')->pluck('total', 'unidad_id');
        $aplicado = DB::table('pago_aplicaciones as a')->join('pagos as p', 'p.id', '=', 'a.pago_id')->where('p.estado', 'aprobado')->groupBy('p.unidad_id')->selectRaw('p.unidad_id, sum(a.monto) as total')->pluck('total', 'unidad_id');
        $responsables = $this->responsables->execute($ids);

        $filas = $unidades->map(function (object $u) use ($debe, $favor, $aplicado, $responsables, $hoy): array {
            $d = $debe[$u->id] ?? null;
            $aFavor = max(0, Dinero::centavos((string) ($favor[$u->id] ?? 0)) - Dinero::centavos((string) ($aplicado[$u->id] ?? 0)));

            return [
                'unidad' => $u->codigo,
                'responsable' => $responsables[(int) $u->id]['nombre'] ?? 'Sin responsable',
                'saldo_pendiente' => self::dinero($d->total ?? 0),
                'vencido' => self::dinero($d->vencido ?? 0),
                'dias_atraso' => $d?->primera === null ? 0 : (int) CarbonImmutable::parse((string) $d->primera)->diffInDays(CarbonImmutable::parse($hoy)),
                'saldo_favor' => Dinero::texto($aFavor),
            ];
        })->sortBy('unidad', SORT_NATURAL)->values()->all();

        return [[
            self::col('unidad', 'Unidad'), self::col('responsable', 'Responsable'), self::col('saldo_pendiente', 'Saldo pendiente', 'dinero'), self::col('vencido', 'Vencido', 'dinero'),
            self::col('dias_atraso', 'Días de atraso', 'entero'), self::col('saldo_favor', 'Saldo a favor', 'dinero'),
        ], $filas];
    }
}
