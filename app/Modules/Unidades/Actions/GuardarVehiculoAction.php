<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar un vehículo en una unidad o editarlo. La placa se guarda
 * normalizada (el modelo la pasa a PBA-1234).
 */
final class GuardarVehiculoAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarVehiculoRequest
     */
    public function execute(array $datos, ?Unidad $unidad = null, ?Vehiculo $vehiculo = null): Vehiculo
    {
        return DB::transaction(function () use ($datos, $unidad, $vehiculo): Vehiculo {
            $vehiculo ??= new Vehiculo(['unidad_id' => $unidad?->id]);
            $vehiculo->fill($datos)->save();

            return $vehiculo;
        });
    }
}
