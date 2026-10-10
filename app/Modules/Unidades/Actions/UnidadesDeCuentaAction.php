<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para la app del residente: las unidades que hoy
 * ocupa la persona de esta cuenta. Puede pagar una unidad quien tiene la relación que la
 * unidad marca como responsable de pago (propietario o inquilino); un residente más la ve
 * pero no paga por ella.
 */
final class UnidadesDeCuentaAction
{
    /**
     * @return list<array{unidad_id: int, codigo: string, relacion: string, puede_pagar: bool}>
     */
    public function execute(int $userId): array
    {
        $ocupantes = Ocupante::query()
            ->vigentes()
            ->whereIn('relacion', ['propietario', 'inquilino', 'residente'])
            ->whereHas('persona', fn ($q) => $q->where('user_id', $userId))
            ->with('unidad')
            ->get();

        $unidades = [];
        foreach ($ocupantes as $o) {
            $unidades[] = [
                'unidad_id' => $o->unidad_id,
                'codigo' => $o->unidad->codigo,
                'relacion' => $o->relacion,
                'puede_pagar' => $o->relacion === $o->unidad->responsable_pago,
            ];
        }

        usort($unidades, fn (array $a, array $b) => strcmp($a['codigo'], $b['codigo']));

        return $unidades;
    }
}
