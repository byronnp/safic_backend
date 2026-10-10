<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: eliminar un bloque. Un bloque con unidades no se elimina: las unidades quedarían
 * sin torre sin que nadie lo decida. Hay que pasarlas a otro bloque primero (409 BLOQUE_CON_UNIDADES).
 */
final class EliminarBloqueAction
{
    public function execute(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $bloque = Bloque::query()->lockForUpdate()->findOrFail($id);

            $unidades = Unidad::query()->where('bloque_id', $bloque->id)->count();
            if ($unidades > 0) {
                throw new ApiException(
                    'BLOQUE_CON_UNIDADES',
                    $unidades === 1
                        ? 'Este bloque tiene 1 unidad. Pásala a otro bloque antes de eliminarlo.'
                        : "Este bloque tiene {$unidades} unidades. Pásalas a otro bloque antes de eliminarlo.",
                    409,
                    ['unidades' => $unidades],
                );
            }

            $bloque->delete();
        });
    }
}
