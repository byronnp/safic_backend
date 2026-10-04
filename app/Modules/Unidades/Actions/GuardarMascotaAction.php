<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Mascota;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar una mascota en una unidad o editarla.
 */
final class GuardarMascotaAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarMascotaRequest
     */
    public function execute(array $datos, ?Unidad $unidad = null, ?Mascota $mascota = null): Mascota
    {
        return DB::transaction(function () use ($datos, $unidad, $mascota): Mascota {
            $mascota ??= new Mascota(['unidad_id' => $unidad?->id]);
            $mascota->fill($datos)->save();

            return $mascota;
        });
    }
}
