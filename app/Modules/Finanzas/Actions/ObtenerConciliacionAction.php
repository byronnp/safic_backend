<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\AjusteConciliacion;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\EstadoCuentaBancario;
use App\Modules\Finanzas\Models\MovimientoBancario;
use Illuminate\Support\Collection;

/**
 * Consulta: un estado de cuenta importado con sus líneas por pestaña (automáticos, sugeridos, sin
 * identificar) y el cuadre del mes: lo que dice la app contra lo que dice el banco.
 */
final class ObtenerConciliacionAction
{
    /** @return list<array<string, mixed>> */
    public function estados(?int $cuentaId, ?string $periodo): array
    {
        return EstadoCuentaBancario::query()
            ->when($cuentaId !== null, fn ($q) => $q->where('cuenta_bancaria_id', $cuentaId))
            ->when($periodo !== null, fn ($q) => $q->where('periodo', $periodo.'-01'))
            ->orderByDesc('periodo')->orderBy('cuenta_bancaria_id')->limit(60)->get()
            ->map(fn (EstadoCuentaBancario $e) => $this->resumen($e))->all();
    }

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $estado = EstadoCuentaBancario::query()->findOrFail($id);
        $movs = MovimientoBancario::query()->where('estado_cuenta_id', $id)->orderBy('fecha')->orderBy('id')->get();

        $fila = fn (MovimientoBancario $m): array => [
            'id' => $m->id, 'fecha' => $m->fecha->toDateString(), 'descripcion' => $m->descripcion, 'referencia' => $m->referencia,
            'monto' => (string) $m->monto, 'estado' => $m->estado, 'tipo' => $m->tipo,
            'pago_id' => $m->pago_id, 'pago_proveedor_id' => $m->pago_proveedor_id,
            'propuesta' => $m->propuesta_tipo === null ? null : ['tipo' => $m->propuesta_tipo, 'id' => $m->propuesta_id, 'texto' => $m->propuesta_texto],
        ];

        $automaticos = $movs->filter(fn (MovimientoBancario $m) => $m->resuelto());

        return $this->resumen($estado) + [
            'automaticos' => $automaticos->map($fila)->values()->all(),
            'sugeridos' => $movs->where('estado', MovimientoBancario::SUGERIDO)->map($fila)->values()->all(),
            'sin_identificar' => $movs->where('estado', MovimientoBancario::SIN_IDENTIFICAR)->map($fila)->values()->all(),
            'cuadre' => $this->cuadre($estado, $movs),
        ];
    }

    /**
     * @param  Collection<int, MovimientoBancario>  $movs
     * @return array<string, mixed>
     */
    public function cuadre(EstadoCuentaBancario $estado, Collection $movs): array
    {
        $suma = fn (callable $filtro): int => Dinero::suma($movs->filter($filtro)->map(fn (MovimientoBancario $m) => (string) $m->monto));
        $resueltos = fn (MovimientoBancario $m): bool => $m->resuelto();

        $pagos = $suma(fn ($m) => $resueltos($m) && $m->tipo === 'pago_residente');
        $intereses = $suma(fn ($m) => $resueltos($m) && $m->tipo === 'interes');
        $gastos = $suma(fn ($m) => $resueltos($m) && $m->tipo === 'pago_proveedor'); // negativo
        $comisiones = $suma(fn ($m) => $resueltos($m) && $m->tipo === 'comision'); // negativo
        $otros = $suma(fn ($m) => $resueltos($m) && $m->tipo === 'otro');
        $ajustes = Dinero::suma(AjusteConciliacion::query()->where('estado_cuenta_id', $estado->id)->pluck('monto')->map(fn ($m) => (string) $m));

        $inicial = $estado->saldo_inicial === null ? null : Dinero::centavos((string) $estado->saldo_inicial);
        $final = $estado->saldo_final === null ? null : Dinero::centavos((string) $estado->saldo_final);
        $saldoApp = $inicial === null ? null : $inicial + $pagos + $intereses + $gastos + $comisiones + $otros + $ajustes;
        $todos = $suma(fn () => true);

        $texto = fn (?int $c): ?string => $c === null ? null : Dinero::texto($c);

        return [
            'saldo_inicial' => $texto($inicial),
            'pagos_residentes' => $texto($pagos),
            'intereses' => $texto($intereses),
            'gastos_pagados' => $texto($gastos),
            'comisiones' => $texto($comisiones),
            'otros' => $texto($otros),
            'ajustes' => $texto($ajustes),
            'saldo_app' => $texto($saldoApp),
            'saldo_banco' => $texto($final),
            'diferencia' => $saldoApp === null || $final === null ? null : Dinero::texto($final - $saldoApp),
            // El archivo «cuadra» si el saldo inicial más todas sus líneas da el saldo final
            'archivo_cuadra' => $inicial === null || $final === null ? null : $inicial + $todos === $final,
        ];
    }

    /** @return array<string, mixed> */
    private function resumen(EstadoCuentaBancario $e): array
    {
        $movs = MovimientoBancario::query()->where('estado_cuenta_id', $e->id)->get(['id', 'estado', 'monto', 'tipo']);

        return [
            'id' => $e->id,
            'cuenta_bancaria_id' => $e->cuenta_bancaria_id,
            'cuenta' => $this->nombreCuenta($e->cuenta_bancaria_id),
            'periodo' => $e->periodo->format('Y-m'),
            'archivo_nombre' => $e->archivo_nombre,
            'movimientos' => $movs->count(),
            'duplicados' => $e->duplicados,
            'pendientes' => $movs->whereIn('estado', [MovimientoBancario::SUGERIDO, MovimientoBancario::SIN_IDENTIFICAR])->count(),
            'conteos' => [
                'automaticos' => $movs->filter(fn (MovimientoBancario $m) => $m->resuelto())->count(),
                'sugeridos' => $movs->where('estado', MovimientoBancario::SUGERIDO)->count(),
                'sin_identificar' => $movs->where('estado', MovimientoBancario::SIN_IDENTIFICAR)->count(),
            ],
        ];
    }

    /** «Banco Pichincha · Corriente ····4821»: lo que se ve sin tener permiso para listar las cuentas. */
    private function nombreCuenta(int $id): string
    {
        $c = CuentaBancaria::query()->find($id);

        return $c === null ? 'Cuenta' : "{$c->banco} · ".ucfirst($c->tipo).' ····'.substr((string) $c->numero, -4);
    }
}
