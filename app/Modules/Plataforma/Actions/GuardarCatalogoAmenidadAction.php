<?php

namespace App\Modules\Plataforma\Actions;

use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caso de uso del super admin: crear o editar un tipo del catálogo global. Las reglas de
 * comportamiento se validan sobre el resultado final (en una edición parcial el valor
 * que no viaja es el que ya tenía): una esencial no se reserva y solo una reservable
 * puede requerir aprobación.
 */
final class GuardarCatalogoAmenidadAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarCatalogoAmenidadRequest
     */
    public function execute(?int $id, array $datos): AmenidadCatalogo
    {
        return DB::transaction(function () use ($id, $datos): AmenidadCatalogo {
            $tipo = $id === null
                ? new AmenidadCatalogo(['reservable' => false, 'esencial' => false, 'requiere_aprobacion' => false, 'activa' => true, 'orden' => ((int) AmenidadCatalogo::query()->max('orden')) + 1])
                : AmenidadCatalogo::query()->lockForUpdate()->findOrFail($id);

            $tipo->fill($datos);

            if ($tipo->esencial && $tipo->reservable) {
                throw ValidationException::withMessages(['esencial' => 'Una amenidad esencial no se reserva: nunca se restringe, ni siquiera a morosos.']);
            }
            if ($tipo->requiere_aprobacion && ! $tipo->reservable) {
                throw ValidationException::withMessages(['requiere_aprobacion' => 'Solo una amenidad reservable puede requerir aprobación.']);
            }
            if (! $tipo->reservable) {
                // Sin reservas no hay duración máxima que sugerir
                $tipo->duracion_maxima_min = null;
            }

            $tipo->save();

            return $tipo;
        });
    }
}
