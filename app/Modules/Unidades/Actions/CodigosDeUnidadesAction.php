<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: el código legible de cada unidad
 * (A-102) a partir de su id, para las listas de pagos y cuotas.
 */
final class CodigosDeUnidadesAction
{
    /**
     * @param  list<int>  $ids
     * @return array<int, string> id → código
     */
    public function execute(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Unidad::query()->withTrashed()->whereIn('id', array_unique($ids))->pluck('codigo', 'id')->map(fn ($c) => (string) $c)->all();
    }
}
