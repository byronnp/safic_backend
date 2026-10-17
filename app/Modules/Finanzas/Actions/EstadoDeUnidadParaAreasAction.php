<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Services\MorosidadService;

/**
 * Contrato público de Finanzas para Reservas (Decreto 462): ¿la unidad tiene hoy la restricción de áreas comunes
 * en vigor?, y ¿está al día (no en mora)? Lo consulta quien reserva, aprueba o da el ingreso a un área.
 */
final class EstadoDeUnidadParaAreasAction
{
    public function __construct(private readonly MorosidadService $morosidad) {}

    public function restringida(int $unidadId): bool
    {
        return $this->morosidad->restringida($unidadId);
    }

    /**
     * @param  list<int>  $unidadIds
     * @return array<int, bool> unidad_id => está al día (no está en mora)
     */
    public function alDia(array $unidadIds): array
    {
        $deudas = $this->morosidad->deudas($unidadIds);
        $convenios = $this->morosidad->convenios($unidadIds);

        $resultado = [];
        foreach ($unidadIds as $id) {
            $resultado[$id] = ! isset($deudas[$id]) || ! $this->morosidad->enMora($deudas[$id], $convenios[$id] ?? null);
        }

        return $resultado;
    }
}
