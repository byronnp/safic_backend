<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Unidades\Actions\UnidadesACobrarAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el administrador emite las cuotas ordinarias de un mes. Cada unidad con
 * cuota mensual recibe una cuota por el valor que ya tiene calculado según el método de
 * cobro. Emitir otra vez el mismo mes solo agrega las unidades que faltaban (no duplica
 * ni cambia lo ya emitido). No se emite antes de que aplique el cobro, ni un mes futuro,
 * ni un mes cerrado.
 */
final class EmitirPeriodoAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly UnidadesACobrarAction $unidades,
    ) {}

    /**
     * @param  string  $periodo  YYYY-MM
     * @return array{periodo: string, creadas: int, existentes: int, sin_cuota: int, total: string, vence_el: string}
     */
    public function execute(string $periodo): array
    {
        $condominioId = $this->tenant->require();
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo)
            ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);

        $config = ConfiguracionCobro::query()->first()
            ?? throw new ApiException('COBRO_SIN_CONFIGURAR', 'Configura primero cómo cobra el condominio (Configuración › Cobro de cuotas).', 409);

        if ($mes->lessThan($config->aplica_desde->startOfMonth())) {
            throw new ApiException('PERIODO_ANTERIOR_AL_COBRO', 'El cobro aplica desde '.$config->aplica_desde->format('m/Y').': no se emiten meses anteriores.', 409);
        }

        if ($mes->greaterThan(CarbonImmutable::parse($this->calendario->hoy())->startOfMonth())) {
            throw new ApiException('PERIODO_FUTURO', 'Solo se emite el mes en curso o uno anterior.', 409);
        }

        $vence = $config->dia_vencimiento === 0 ? $mes->endOfMonth() : $mes->day($config->dia_vencimiento);

        return DB::transaction(function () use ($condominioId, $mes, $vence): array {
            $existente = PeriodoFinanciero::query()->where('periodo', $mes->toDateString())->lockForUpdate()->first();
            if ($existente?->estado === PeriodoFinanciero::CERRADO) {
                throw new ApiException('PERIODO_CERRADO', 'Ese mes ya está cerrado.', 409);
            }

            $aCobrar = $this->unidades->execute();
            if ($aCobrar['unidades'] === []) {
                throw new ApiException('SIN_UNIDADES_A_COBRAR', 'Ninguna unidad tiene cuota mensual. Revisa el cobro de cuotas y las unidades.', 409);
            }

            $ya = Cuota::query()->where('periodo', $mes->toDateString())->where('concepto', Cuota::ORDINARIA)->pluck('unidad_id')->flip();
            $nuevas = [];
            $ahora = now();
            foreach ($aCobrar['unidades'] as $u) {
                if ($ya->has($u['id'])) {
                    continue;
                }
                $nuevas[] = [
                    'condominio_id' => $condominioId, 'unidad_id' => $u['id'], 'periodo' => $mes->toDateString(),
                    'concepto' => Cuota::ORDINARIA, 'monto' => $u['cuota_mensual'], 'pagado' => '0.00',
                    'vence_el' => $vence->toDateString(), 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }

            foreach (array_chunk($nuevas, 500) as $lote) {
                DB::table('cuotas')->insert($lote);
            }

            $periodo = $existente ?? new PeriodoFinanciero(['periodo' => $mes->toDateString(), 'estado' => PeriodoFinanciero::ABIERTO]);
            $periodo->emitido_en ??= $ahora;
            $periodo->save();

            $total = (string) Cuota::query()->where('periodo', $mes->toDateString())->where('concepto', Cuota::ORDINARIA)
                ->selectRaw('coalesce(sum(monto), 0)::numeric(14,2) as total')->value('total');

            return [
                'periodo' => $mes->format('Y-m'),
                'creadas' => count($nuevas),
                'existentes' => $ya->count(),
                'sin_cuota' => $aCobrar['sin_cuota'],
                'total' => $total,
                'vence_el' => $vence->toDateString(),
            ];
        });
    }
}
