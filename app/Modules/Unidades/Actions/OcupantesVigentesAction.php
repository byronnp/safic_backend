<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;

/**
 * Consulta pública del módulo Unidades para Garita: las personas que hoy viven en una unidad (propietario, inquilino o residente),
 * con su correo si lo tienen. Sirve para avisar de un paquete y para saber quién puede retirarlo.
 */
final class OcupantesVigentesAction
{
    /**
     * Solo los que tienen cuenta en la app (a ellos se les puede preguntar desde ahí).
     *
     * @return list<array{id: int, nombre: string, relacion: string, email: string|null, tiene_cuenta: bool}>
     */
    public function conCuenta(int $unidadId): array
    {
        return array_values(array_filter($this->execute($unidadId), fn (array $o): bool => $o['tiene_cuenta']));
    }

    /** @return list<array{id: int, nombre: string, relacion: string, email: string|null, tiene_cuenta: bool}> */
    public function execute(int $unidadId): array
    {
        $resultado = [];
        $vistas = [];
        foreach (Ocupante::query()->vigentes()->where('unidad_id', $unidadId)->whereIn('relacion', ['propietario', 'inquilino', 'residente'])->with('persona')->orderByDesc('es_principal')->orderBy('id')->get() as $o) {
            if ($o->persona->trashed() || isset($vistas[$o->persona_id])) {
                continue;
            }
            $vistas[$o->persona_id] = true;
            $resultado[] = ['id' => $o->id, 'nombre' => $o->persona->nombreCompleto(), 'relacion' => $o->relacion, 'email' => $o->persona->email, 'tiene_cuenta' => $o->persona->user_id !== null];
        }

        return $resultado;
    }
}
