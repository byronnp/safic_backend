<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para Garita: las personas que hoy viven en una unidad (propietario, inquilino o residente),
 * con su correo si lo tienen. Sirve para avisar de un paquete y para saber quién puede retirarlo.
 */
final class OcupantesVigentesAction
{
    /** @return list<array{id: int, nombre: string, relacion: string, email: string|null}> */
    public function execute(int $unidadId): array
    {
        $resultado = [];
        $vistas = [];
        foreach (Ocupante::query()->vigentes()->where('unidad_id', $unidadId)->whereIn('relacion', ['propietario', 'inquilino', 'residente'])->with('persona')->orderByDesc('es_principal')->orderBy('id')->get() as $o) {
            if ($o->persona->trashed() || isset($vistas[$o->persona_id])) {
                continue;
            }
            $vistas[$o->persona_id] = true;
            $resultado[] = ['id' => $o->id, 'nombre' => $o->persona->nombreCompleto(), 'relacion' => $o->relacion, 'email' => $o->persona->email];
        }

        return $resultado;
    }
}
