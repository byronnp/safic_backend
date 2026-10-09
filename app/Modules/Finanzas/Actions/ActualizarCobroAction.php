<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Unidades\Actions\ResumenCobroUnidadesAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cambiar cómo cobra el condominio sus cuotas (Configuración › Cobro
 * de cuotas). Las cuotas ya emitidas no cambian: el cambio rige desde `aplica_desde`.
 * No se puede pasar a un método cuyo dato falta en alguna unidad.
 */
final class ActualizarCobroAction
{
    public function __construct(
        private readonly GuardarConfiguracionCobroAction $guardar,
        private readonly ResumenCobroUnidadesAction $unidades,
    ) {}

    /**
     * @param  array{metodo: string, cuota_general?: string|null, presupuesto_mensual?: string|null, valores_tipo?: list<array{tipo: string, valor: string}>, dia_vencimiento: int, aplica_desde: string}  $datos  `aplica_desde` como Y-m
     */
    public function execute(array $datos): ConfiguracionCobro
    {
        return DB::transaction(function () use ($datos): ConfiguracionCobro {
            $resumen = $this->unidades->execute();

            if ($datos['metodo'] === ConfiguracionCobro::METODO_ALICUOTA && $resumen['sin_alicuota'] > 0) {
                throw new ApiException(
                    'UNIDADES_SIN_ALICUOTA',
                    "No se puede cobrar por alícuota: {$resumen['sin_alicuota']} unidades no tienen alícuota. Complétalas primero.",
                    409,
                    ['unidades' => $resumen['sin_alicuota']],
                );
            }

            if ($datos['metodo'] === ConfiguracionCobro::METODO_UNIDAD && $resumen['sin_cuota_mensual'] > 0) {
                throw new ApiException(
                    'UNIDADES_SIN_CUOTA',
                    "No se puede cobrar por unidad: {$resumen['sin_cuota_mensual']} unidades no tienen cuota. Complétalas primero.",
                    409,
                    ['unidades' => $resumen['sin_cuota_mensual']],
                );
            }

            return $this->guardar->execute([...$datos, 'aplica_desde' => $datos['aplica_desde'].'-01']);
        });
    }
}
