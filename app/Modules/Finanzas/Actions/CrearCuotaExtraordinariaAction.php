<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Unidades\Actions\UnidadesACobrarAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración: cobrar una cuota extraordinaria (pintura de fachada, reparación…)
 * a las unidades que pagan cuota. Se reparte en partes iguales («monto por unidad») o según la
 * alícuota de cada una («total a repartir», sin perder ni sobrar centavos). Va en el mes elegido,
 * con su detalle y fecha de vencimiento; no sirve en un mes cerrado.
 */
final class CrearCuotaExtraordinariaAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly UnidadesACobrarAction $unidades,
    ) {}

    /**
     * @param  array{detalle: string, periodo: string, vence_el: string, modo: string, monto: string}  $datos
     * @return array{creadas: int, total: string, vence_el: string}
     */
    public function execute(array $datos): array
    {
        $condominioId = $this->tenant->require();
        $mes = CarbonImmutable::createFromFormat('!Y-m', $datos['periodo'])->toDateString();
        $vence = CarbonImmutable::parse($datos['vence_el'])->toDateString();
        $detalle = trim($datos['detalle']);

        if ($vence < $this->calendario->hoy()) {
            throw new ApiException('VENCIMIENTO_PASADO', 'La fecha de vencimiento no puede ser pasada.', 422);
        }

        return DB::transaction(function () use ($condominioId, $mes, $vence, $detalle, $datos): array {
            if (PeriodoFinanciero::query()->where('periodo', $mes)->where('estado', PeriodoFinanciero::CERRADO)->exists()) {
                throw new ApiException('PERIODO_CERRADO', 'Ese mes ya está cerrado.', 409);
            }

            $unidades = $this->unidades->execute()['unidades'];
            if ($unidades === []) {
                throw new ApiException('SIN_UNIDADES_A_COBRAR', 'Ninguna unidad paga cuota. Revisa el cobro de cuotas y las unidades.', 409);
            }

            if ($datos['modo'] === 'por_unidad') {
                $partes = array_fill_keys(array_column($unidades, 'id'), Dinero::centavos($datos['monto']));
            } else {
                $sinAlicuota = array_filter($unidades, fn (array $u) => $u['alicuota'] === null || (float) $u['alicuota'] <= 0);
                if ($sinAlicuota !== []) {
                    throw new ApiException('UNIDADES_SIN_ALICUOTA', 'Para repartir por alícuota todas las unidades necesitan su alícuota. Faltan: '.implode(', ', array_slice(array_column($sinAlicuota, 'codigo'), 0, 8)).'.', 422);
                }
                $pesos = [];
                foreach ($unidades as $u) {
                    $pesos[$u['id']] = (float) $u['alicuota'];
                }
                $partes = Dinero::repartir(Dinero::centavos($datos['monto']), $pesos);
            }

            $existen = Cuota::query()->where('periodo', $mes)->where('concepto', 'extraordinaria')->whereRaw('lower(detalle) = ?', [mb_strtolower($detalle)])->exists();
            if ($existen) {
                throw new ApiException('EXTRAORDINARIA_EXISTENTE', 'Ya cobraste una cuota extraordinaria con ese detalle en ese mes.', 409);
            }

            $ahora = now();
            $filas = [];
            foreach ($partes as $unidadId => $centavos) {
                if ($centavos <= 0) {
                    continue;
                }
                $filas[] = [
                    'condominio_id' => $condominioId, 'unidad_id' => $unidadId, 'periodo' => $mes, 'concepto' => 'extraordinaria',
                    'detalle' => $detalle, 'monto' => Dinero::texto($centavos), 'pagado' => '0.00', 'vence_el' => $vence,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
            if ($filas === []) {
                throw new ApiException('MONTO_INSUFICIENTE', 'El monto es muy pequeño para repartirlo entre las unidades.', 422);
            }
            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('cuotas')->insert($lote);
            }

            return ['creadas' => count($filas), 'total' => Dinero::texto(array_sum($partes)), 'vence_el' => $vence];
        });
    }
}
