<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Mascota;
use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: quitar un vehículo o una mascota de la unidad (borrado lógico: la
 * placa queda libre para registrarla en otra unidad).
 */
final class EliminarRegistroDeUnidadAction
{
    public function execute(Vehiculo|Mascota $registro): void
    {
        DB::transaction(fn () => $registro->delete());
    }
}
