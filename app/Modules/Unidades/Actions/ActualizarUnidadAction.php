<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: editar una unidad. Si pasa de un tipo que no cuenta para el total
 * (parqueadero, bodega) a uno que sí, exige cupo como un alta.
 */
final class ActualizarUnidadAction
{
    public function __construct(private readonly LimiteUnidades $limite) {}

    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarUnidadRequest
     */
    public function execute(Unidad $unidad, array $datos): Unidad
    {
        return DB::transaction(function () use ($unidad, $datos): Unidad {
            $unidad = Unidad::query()->whereKey($unidad->id)->lockForUpdate()->firstOrFail();
            $tipoNuevo = (string) ($datos['tipo'] ?? $unidad->tipo);

            if (! LimiteUnidades::cuenta($unidad->tipo) && LimiteUnidades::cuenta($tipoNuevo)) {
                $this->limite->asegurarCupo();
            }

            $unidad->update($datos);

            return $unidad->load('bloque');
        });
    }
}
