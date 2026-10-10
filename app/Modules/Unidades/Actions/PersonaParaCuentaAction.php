<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para Usuarios: los datos mínimos de una persona que
 * hoy ocupa una unidad (propietaria, inquilina o residente), para crearle la cuenta con la
 * que entra a la app. La cédula sale solo cuando es una cédula.
 */
final class PersonaParaCuentaAction
{
    /**
     * @return array{persona_id: int, nombre: string, email: string|null, cedula: string|null, unidad: string, user_id: int|null}|null
     */
    public function execute(int $personaId): ?array
    {
        $ocupante = Ocupante::query()
            ->vigentes()
            ->where('persona_id', $personaId)
            ->whereIn('relacion', ['propietario', 'inquilino', 'residente'])
            ->with(['persona', 'unidad'])
            ->orderByDesc('es_principal')
            ->first();

        $persona = $ocupante?->persona;
        if ($ocupante === null || $persona === null || $persona->trashed()) {
            return null;
        }

        return [
            'persona_id' => $persona->id,
            'nombre' => $persona->nombreCompleto(),
            'email' => $persona->email,
            'cedula' => $persona->tipo_documento === 'cedula' ? $persona->documento : null,
            'unidad' => $ocupante->unidad->codigo,
            'user_id' => $persona->user_id,
        ];
    }
}
