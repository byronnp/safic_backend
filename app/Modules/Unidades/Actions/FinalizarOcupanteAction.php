<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Modules\Unidades\Models\Ocupante;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: terminar una ocupación (fin de arriendo, venta, mudanza). No borra:
 * llena fecha_fin y el registro queda en el historial.
 */
final class FinalizarOcupanteAction
{
    public function __construct(private readonly Calendario $calendario) {}

    public function execute(Ocupante $ocupante, string $fechaFin): Ocupante
    {
        return DB::transaction(function () use ($ocupante, $fechaFin): Ocupante {
            $ocupante = Ocupante::query()->whereKey($ocupante->id)->lockForUpdate()->firstOrFail();

            if ($ocupante->fecha_fin !== null && $ocupante->fecha_fin->toDateString() < $this->calendario->hoy()) {
                throw new ApiException('OCUPANTE_FINALIZADO', 'Esta ocupación ya terminó.', 409);
            }

            $ocupante->update(['fecha_fin' => $fechaFin]);

            return $ocupante->load('persona');
        });
    }
}
