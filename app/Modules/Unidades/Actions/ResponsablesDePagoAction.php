<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: quién responde por el pago de cada unidad
 * (la persona que hoy ocupa la unidad con la relación que la unidad marca como responsable de
 * pago: propietario o inquilino; el principal primero).
 */
final class ResponsablesDePagoAction
{
    /**
     * @param  list<int>  $unidadIds
     * @return array<int, array{nombre: string, relacion: string}> unidad_id → responsable
     */
    public function execute(array $unidadIds): array
    {
        if ($unidadIds === []) {
            return [];
        }

        $responsable = Unidad::query()->withTrashed()->whereIn('id', $unidadIds)->pluck('responsable_pago', 'id');
        $resultado = [];

        $ocupantes = Ocupante::query()->vigentes()->whereIn('unidad_id', $unidadIds)->with('persona')->orderByDesc('es_principal')->orderBy('id')->get();
        foreach ($ocupantes as $o) {
            if (isset($resultado[$o->unidad_id]) || ($responsable[$o->unidad_id] ?? null) !== $o->relacion || $o->persona->trashed()) {
                continue;
            }
            $resultado[$o->unidad_id] = ['nombre' => $o->persona->nombreCompleto(), 'relacion' => $o->relacion];
        }

        return $resultado;
    }
}
