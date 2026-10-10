<?php

namespace App\Modules\Finanzas\Services;

use Illuminate\Support\Facades\DB;

/**
 * Saldo a favor de una unidad: lo que pagó (pagos aprobados) menos lo que ya se aplicó a
 * cuotas. No se guarda ni se edita a mano: se calcula siempre de los movimientos, en
 * PostgreSQL como numeric (nunca float).
 */
final class SaldoFavorService
{
    public function de(int $unidadId): string
    {
        $fila = DB::selectOne(
            "select (
                coalesce((select sum(monto) from pagos where unidad_id = ? and estado = 'aprobado'), 0)
                - coalesce((select sum(a.monto) from pago_aplicaciones a join pagos p on p.id = a.pago_id where p.unidad_id = ? and p.estado = 'aprobado'), 0)
             )::numeric(14,2) as saldo",
            [$unidadId, $unidadId],
        );

        return (string) $fila->saldo;
    }
}
