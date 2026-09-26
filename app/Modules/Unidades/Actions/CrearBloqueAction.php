<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Bloque;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: crear un bloque en el condominio activo.
 * Patrón de Actions: una clase por caso de uso, con su transacción.
 */
final class CrearBloqueAction
{
    /**
     * @param  array{nombre: string, orden?: int}  $datos
     */
    public function execute(array $datos): Bloque
    {
        return DB::transaction(fn () => Bloque::create([
            'nombre' => $datos['nombre'],
            'orden' => $datos['orden'] ?? 0,
        ]));
    }
}
