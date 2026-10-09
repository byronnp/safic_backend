<?php

namespace App\Modules\Amenidades\Actions;

use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cambiar dónde está una amenidad, ponerla o sacarla de mantenimiento, o
 * desactivarla/reactivarla. Siempre dentro del condominio activo: otro condominio responde 404.
 */
final class ActualizarAmenidadAction
{
    /**
     * @param  array{ubicacion?: string|null, activa?: bool, mantenimiento_hasta?: string|null}  $datos  Solo lo que cambia
     */
    public function execute(int $id, array $datos): CondominioAmenidad
    {
        return DB::transaction(function () use ($id, $datos): CondominioAmenidad {
            $amenidad = CondominioAmenidad::query()->lockForUpdate()->findOrFail($id);
            $amenidad->fill($datos)->save();

            return $amenidad;
        });
    }
}
