<?php

namespace App\Modules\Unidades\Actions;

use App\Core\Subscriptions\LimiteUnidades;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: cuántas unidades hay por
 * tipo y cuáles no tienen los datos que pide un método de cobro. Sirve para
 * proyectar la emisión y para no cambiar de método con unidades incompletas.
 */
final class ResumenCobroUnidadesAction
{
    /**
     * @return array{
     *     por_tipo: array<string, int>,
     *     con_cupo: int,
     *     suma_cuotas: string,
     *     sin_alicuota: int,
     *     sin_cuota_mensual: int
     * }
     */
    public function execute(): array
    {
        $porTipo = array_fill_keys(Unidad::TIPOS, 0);
        foreach (Unidad::query()->selectRaw('tipo, count(*) as total')->groupBy('tipo')->pluck('total', 'tipo') as $tipo => $total) {
            $porTipo[$tipo] = (int) $total;
        }

        return [
            'por_tipo' => $porTipo,
            'con_cupo' => array_sum(array_intersect_key($porTipo, array_flip(LimiteUnidades::TIPOS_CON_CUPO))),
            // La suma se hace en PostgreSQL como numeric: nunca pasa por float
            'suma_cuotas' => (string) Unidad::query()->selectRaw('coalesce(sum(cuota_mensual), 0)::numeric(14,2) as suma')->value('suma'),
            'sin_alicuota' => Unidad::query()->whereNull('alicuota')->count(),
            'sin_cuota_mensual' => Unidad::query()->whereNull('cuota_mensual')->count(),
        ];
    }
}
