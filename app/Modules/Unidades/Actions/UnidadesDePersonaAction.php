<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para Finanzas: las unidades de las que una persona es hoy
 * propietaria (para saber si está en mora).
 */
final class UnidadesDePersonaAction
{
    /**
     * @return list<int>
     */
    public function propietaria(int $personaId): array
    {
        return Ocupante::query()->vigentes()->where('relacion', 'propietario')->where('persona_id', $personaId)
            ->pluck('unidad_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
