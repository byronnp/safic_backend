<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\ReglaArea;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: fijar las reglas de uso de un área común. Solo las amenidades reservables son áreas.
 * La aprobación del administrador y la restricción por mora se guardan en la amenidad.
 */
final class GuardarReglasAreaAction
{
    /**
     * @param  array<string, mixed>  $datos  Las reglas más `requiere_aprobacion` y `restringible_por_mora`
     */
    public function execute(int $amenidadId, array $datos): CondominioAmenidad
    {
        return DB::transaction(function () use ($amenidadId, $datos): CondominioAmenidad {
            $area = CondominioAmenidad::query()->lockForUpdate()->findOrFail($amenidadId);
            if (! $area->reservable) {
                throw new ApiException('AREA_NO_RESERVABLE', 'Esta amenidad no es reservable. Márcala como reservable en Configuración › Amenidades.', 409);
            }

            $area->forceFill([
                'requiere_aprobacion' => (bool) $datos['requiere_aprobacion'],
                'esencial' => ! (bool) $datos['restringible_por_mora'],
            ])->save();

            unset($datos['requiere_aprobacion'], $datos['restringible_por_mora']);
            $datos['dias_semana'] = array_values(array_unique(array_map('intval', $datos['dias_semana'])));
            sort($datos['dias_semana']);

            $regla = ReglaArea::query()->where('amenidad_id', $area->id)->lockForUpdate()->first() ?? new ReglaArea(['amenidad_id' => $area->id]);
            $regla->fill($datos)->save();

            return $area;
        });
    }
}
