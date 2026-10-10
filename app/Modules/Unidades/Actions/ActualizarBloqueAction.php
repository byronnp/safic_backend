<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Bloque;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cambiar el nombre o el orden de un bloque. Solo se guarda lo que llega.
 */
final class ActualizarBloqueAction
{
    /**
     * @param  array{nombre?: string, orden?: int}  $datos
     */
    public function execute(int $id, array $datos): Bloque
    {
        return DB::transaction(function () use ($id, $datos): Bloque {
            $bloque = Bloque::query()->lockForUpdate()->findOrFail($id);
            $bloque->fill(array_intersect_key($datos, array_flip(['nombre', 'orden'])))->save();

            return $bloque;
        });
    }
}
