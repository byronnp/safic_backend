<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\PeriodoFinanciero;
use Illuminate\Support\Facades\DB;

/**
 * Consulta: los meses emitidos del condominio, del más reciente al más antiguo, con lo
 * esperado y lo recaudado de cada uno (para el selector de mes del resumen).
 */
final class ListarPeriodosAction
{
    /**
     * @return list<array{periodo: string, estado: string, esperado: string, recaudado: string}>
     */
    public function execute(): array
    {
        $montos = DB::table('cuotas')
            ->where('concepto', 'ordinaria')
            ->selectRaw('periodo, sum(monto)::numeric(14,2) as esperado, sum(pagado)::numeric(14,2) as recaudado')
            ->groupBy('periodo')
            ->get()
            ->keyBy(fn ($f) => substr((string) $f->periodo, 0, 7));

        return PeriodoFinanciero::query()->orderByDesc('periodo')->get()
            ->map(fn (PeriodoFinanciero $p) => [
                'periodo' => $p->periodo->format('Y-m'),
                'estado' => $p->estado,
                'esperado' => (string) ($montos[$p->periodo->format('Y-m')]->esperado ?? '0.00'),
                'recaudado' => (string) ($montos[$p->periodo->format('Y-m')]->recaudado ?? '0.00'),
            ])
            ->values()
            ->all();
    }
}
