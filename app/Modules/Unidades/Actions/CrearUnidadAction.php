<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar una unidad en el condominio activo, dentro del total
 * de unidades contratadas (409 LIMITE_UNIDADES si no hay cupo).
 */
final class CrearUnidadAction
{
    public function __construct(private readonly LimiteUnidades $limite) {}

    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarUnidadRequest
     */
    public function execute(array $datos): Unidad
    {
        return DB::transaction(function () use ($datos): Unidad {
            if (LimiteUnidades::cuenta((string) $datos['tipo'])) {
                $this->limite->asegurarCupo();
            }

            return Unidad::create($datos)->load('bloque');
        });
    }
}
