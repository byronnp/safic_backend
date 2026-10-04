<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Persona;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: crear o editar una persona del condominio activo. El documento y
 * el teléfono se cifran y el hash del documento se recalcula en el modelo.
 */
final class GuardarPersonaAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarPersonaRequest
     */
    public function execute(array $datos, ?Persona $persona = null): Persona
    {
        return DB::transaction(function () use ($datos, $persona): Persona {
            $persona ??= new Persona;
            $persona->fill($datos)->save();

            return $persona;
        });
    }
}
