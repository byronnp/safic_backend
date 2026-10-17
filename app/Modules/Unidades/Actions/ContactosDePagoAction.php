<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: a quién avisar del pago de cada unidad (la
 * persona que responde por el pago, con su correo si lo tiene). Es lo mismo que
 * ResponsablesDePagoAction, más el correo para los recordatorios.
 */
final class ContactosDePagoAction
{
    /**
     * @param  list<int>  $unidadIds
     * @return array<int, array{nombre: string, relacion: string, email: string|null}> unidad_id → contacto
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
            $resultado[$o->unidad_id] = ['nombre' => $o->persona->nombreCompleto(), 'relacion' => $o->relacion, 'email' => $o->persona->email];
        }

        return $resultado;
    }
}
