<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\PagoProveedor;
use Illuminate\Support\Facades\DB;

/**
 * Pagos hechos a proveedores, del más reciente al más antiguo, con las facturas que cubrió.
 */
final class ListarPagosProveedorAction
{
    /** @return list<array<string, mixed>> */
    public function execute(?int $proveedorId): array
    {
        $pagos = PagoProveedor::query()->with('proveedor:id,razon_social,ruc')
            ->when($proveedorId !== null, fn ($q) => $q->where('proveedor_id', $proveedorId))
            ->orderByDesc('fecha_pago')->orderByDesc('id')->limit(200)->get();

        $aplicado = DB::table('pago_proveedor_aplicaciones as a')
            ->join('gastos as g', 'g.id', '=', 'a.gasto_id')
            ->whereIn('a.pago_proveedor_id', $pagos->pluck('id')->all())
            ->get(['a.pago_proveedor_id', 'a.gasto_id', 'a.monto', 'g.numero'])
            ->groupBy('pago_proveedor_id');

        return $pagos->map(fn (PagoProveedor $p): array => $this->fila($p, $aplicado->get($p->id)?->all() ?? []))->all();
    }

    /**
     * @param  list<object>  $aplicaciones
     * @return array<string, mixed>
     */
    public function fila(PagoProveedor $p, array $aplicaciones): array
    {
        return [
            'id' => $p->id,
            'codigo' => $p->codigo(),
            'proveedor' => ['id' => $p->proveedor_id, 'razon_social' => $p->proveedor->razon_social, 'ruc' => $p->proveedor->ruc],
            'monto' => $p->monto,
            'fecha_pago' => $p->fecha_pago->toDateString(),
            'referencia' => $p->referencia,
            'estado' => $p->estado,
            'motivo_anulacion' => $p->motivo_anulacion,
            'tiene_comprobante' => $p->comprobante_ruta !== null,
            'facturas' => array_map(fn (object $a): array => ['gasto_id' => (int) $a->gasto_id, 'numero' => $a->numero, 'monto' => $a->monto], $aplicaciones),
        ];
    }

    /** @return array<string, mixed> */
    public function uno(int $id): array
    {
        $p = PagoProveedor::query()->with('proveedor:id,razon_social,ruc')->findOrFail($id);
        $aplicado = DB::table('pago_proveedor_aplicaciones as a')->join('gastos as g', 'g.id', '=', 'a.gasto_id')
            ->where('a.pago_proveedor_id', $id)->get(['a.pago_proveedor_id', 'a.gasto_id', 'a.monto', 'g.numero'])->all();

        return $this->fila($p, $aplicado);
    }
}
