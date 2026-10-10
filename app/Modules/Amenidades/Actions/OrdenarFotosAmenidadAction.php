<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Amenidades\Models\AmenidadFoto;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cambiar el orden de las fotos de una amenidad (la primera es la portada).
 * Se manda la lista completa de ids en el orden nuevo; si falta o sobra alguno, se rechaza
 * para no perder una foto por una lista desactualizada.
 */
final class OrdenarFotosAmenidadAction
{
    /**
     * @param  list<int>  $ids
     */
    public function execute(int $amenidadId, array $ids): void
    {
        DB::transaction(function () use ($amenidadId, $ids): void {
            $amenidad = CondominioAmenidad::query()->lockForUpdate()->findOrFail($amenidadId);
            $fotos = AmenidadFoto::query()->where('amenidad_id', $amenidad->id)->get()->keyBy('id');

            $pedidas = collect($ids)->map(fn ($id) => (int) $id);
            if ($pedidas->count() !== $fotos->count() || $pedidas->unique()->count() !== $pedidas->count() || $pedidas->diff($fotos->keys())->isNotEmpty()) {
                throw new ApiException('FOTOS_DESACTUALIZADAS', 'La lista de fotos cambió. Recarga e intenta de nuevo.', 409);
            }

            foreach ($pedidas->values() as $posicion => $id) {
                $fotos[$id]->update(['orden' => $posicion + 1]);
            }
        });
    }
}
