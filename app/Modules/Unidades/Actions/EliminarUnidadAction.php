<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: eliminar una unidad. Es un borrado lógico (queda el historial) y
 * libera su cupo del total contratado.
 */
final class EliminarUnidadAction
{
    public function execute(Unidad $unidad): void
    {
        DB::transaction(fn () => $unidad->delete());
    }
}
