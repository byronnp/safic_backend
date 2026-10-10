<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: los datos de la cabecera del estado de
 * cuenta de una unidad (código, ubicación y descripción).
 */
final class UnidadEstadoCuentaAction
{
    /**
     * @return array{id: int, codigo: string, tipo: string, ubicacion: string}|null
     */
    public function execute(int $unidadId): ?array
    {
        $u = Unidad::query()->with('bloque')->find($unidadId);

        if ($u === null) {
            return null;
        }

        $partes = array_filter([$u->bloque?->nombre, $u->piso !== null ? 'Piso '.$u->piso : null]);

        return ['id' => $u->id, 'codigo' => $u->codigo, 'tipo' => $u->tipo, 'ubicacion' => implode(' · ', $partes)];
    }
}
