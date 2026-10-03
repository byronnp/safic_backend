<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Models\CobroValorTipo;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso público del módulo Finanzas: guardar cómo cobra el condominio activo
 * sus cuotas. Lo usan el asistente de alta (Plataforma) y, en S2, la pantalla
 * Configuración › Cobro de cuotas. Debe correr dentro de un condominio activo.
 */
final class GuardarConfiguracionCobroAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{metodo: string, cuota_general?: string|null, presupuesto_mensual?: string|null, valores_tipo?: list<array{tipo: string, valor: string}>, dia_vencimiento: int, aplica_desde: string}  $datos
     */
    public function execute(array $datos): ConfiguracionCobro
    {
        $this->tenant->require();

        return DB::transaction(function () use ($datos): ConfiguracionCobro {
            $metodo = $datos['metodo'];

            $configuracion = ConfiguracionCobro::query()->firstOrNew();
            $configuracion->fill([
                'metodo' => $metodo,
                'cuota_general' => $metodo === ConfiguracionCobro::METODO_GENERAL ? $datos['cuota_general'] : null,
                'presupuesto_mensual' => $metodo === ConfiguracionCobro::METODO_ALICUOTA ? $datos['presupuesto_mensual'] : null,
                'dia_vencimiento' => $datos['dia_vencimiento'],
                'aplica_desde' => $datos['aplica_desde'],
            ])->save();

            CobroValorTipo::query()->delete();

            if ($metodo === ConfiguracionCobro::METODO_TIPO) {
                foreach ($datos['valores_tipo'] ?? [] as $valor) {
                    CobroValorTipo::create(['tipo_unidad' => $valor['tipo'], 'valor' => $valor['valor']]);
                }
            }

            return $configuracion;
        });
    }
}
