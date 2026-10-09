<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para la directiva: las personas que hoy son
 * propietarias de una unidad (solo ellas pueden ocupar un cargo). Datos mínimos: la
 * cédula sale solo cuando es una cédula, para crear la cuenta de quien asume el cargo.
 */
final class PropietariosVigentesAction
{
    /**
     * @param  list<int>|null  $personaIds  Limitar a estas personas
     * @return list<array{persona_id: int, nombre: string, email: string|null, cedula: string|null, unidad: string}>
     */
    public function execute(?array $personaIds = null): array
    {
        $ocupantes = Ocupante::query()
            ->vigentes()
            ->where('relacion', 'propietario')
            ->when($personaIds !== null, fn ($q) => $q->whereIn('persona_id', $personaIds))
            ->with(['persona', 'unidad'])
            ->orderByDesc('es_principal')
            ->get();

        $porPersona = [];
        foreach ($ocupantes as $ocupante) {
            $persona = $ocupante->persona;
            // Una persona dada de baja no es candidata; una con varias unidades sale con la primera
            if ($persona->trashed() || isset($porPersona[$persona->id])) {
                continue;
            }

            $porPersona[$persona->id] = [
                'persona_id' => $persona->id,
                'nombre' => $persona->nombreCompleto(),
                'email' => $persona->email,
                'cedula' => $persona->tipo_documento === 'cedula' ? $persona->documento : null,
                'unidad' => $ocupante->unidad->codigo,
            ];
        }

        usort($porPersona, fn (array $a, array $b) => strcmp($a['nombre'], $b['nombre']));

        return $porPersona;
    }
}
