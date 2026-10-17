<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\RecurrenteOcurrencia;

/**
 * Consulta: las facturas del proveedor de un gasto recurrente que aún no cubren ningún gasto
 * recurrente (las más recientes primero), para elegir cuál adjuntar.
 */
final class ListarFacturasCandidatasAction
{
    /** @return list<array<string, mixed>> */
    public function execute(int $recurrenteId): array
    {
        $r = GastoRecurrente::query()->findOrFail($recurrenteId);
        $usadas = RecurrenteOcurrencia::query()->whereNotNull('gasto_id')->pluck('gasto_id')->all();

        return Gasto::query()->where('proveedor_id', $r->proveedor_id)->whereNotIn('id', $usadas)->whereIn('estado', ['por_aprobar', 'aprobada', 'pagada'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')->limit(20)->get()
            ->map(fn (Gasto $g): array => [
                'id' => $g->id, 'numero' => $g->numero, 'fecha_emision' => $g->fecha_emision->toDateString(),
                'total' => (string) $g->total, 'estado' => $g->estado,
            ])->all();
    }
}
