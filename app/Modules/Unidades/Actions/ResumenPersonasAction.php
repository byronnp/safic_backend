<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;

/**
 * Consulta pública del módulo Unidades: nombre de unas personas y la unidad donde viven
 * o son dueñas hoy (para mostrarlas en la directiva). Sin documentos ni datos de contacto.
 */
final class ResumenPersonasAction
{
    /**
     * @param  list<int>  $personaIds
     * @return array<int, array{nombre: string, unidad: string|null, propietario: bool}>
     */
    public function execute(array $personaIds): array
    {
        if ($personaIds === []) {
            return [];
        }

        $resumen = [];
        foreach (Persona::query()->withTrashed()->whereIn('id', $personaIds)->get() as $persona) {
            $resumen[$persona->id] = ['nombre' => $persona->nombreCompleto(), 'unidad' => null, 'propietario' => false];
        }

        $ocupantes = Ocupante::query()
            ->vigentes()
            ->whereIn('persona_id', $personaIds)
            ->whereIn('relacion', ['propietario', 'residente', 'inquilino'])
            ->with('unidad')
            ->orderByRaw("case relacion when 'propietario' then 0 when 'residente' then 1 else 2 end")
            ->get();

        foreach ($ocupantes as $ocupante) {
            // Solo personas de la consulta (una persona dada de baja sigue apareciendo por su historial)
            if (! isset($resumen[$ocupante->persona_id])) {
                continue;
            }

            if ($resumen[$ocupante->persona_id]['unidad'] === null) {
                $resumen[$ocupante->persona_id]['unidad'] = $ocupante->unidad->codigo;
            }
            if ($ocupante->relacion === 'propietario') {
                $resumen[$ocupante->persona_id]['propietario'] = true;
            }
        }

        return $resumen;
    }
}
