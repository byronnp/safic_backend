<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use Illuminate\Support\Facades\DB;

/**
 * Cómo se aplica un pago a las cuotas de una unidad:
 * - Cuotas completas, de la más antigua a la más reciente.
 * - Lo que sobra queda como saldo a favor (se calcula; no se guarda).
 * - Tolerancia bancaria: si falta menos que la tolerancia para completar la siguiente cuota, se
 *   da por pagada y esos centavos quedan como ajuste (no como deuda).
 * Se usa el dinero recibido más el saldo a favor que la unidad ya tenía.
 */
final class AplicacionPagoService
{
    public function __construct(private readonly SaldoFavorService $saldoFavor) {}

    /**
     * Cálculo puro: a qué cuotas alcanza el dinero.
     *
     * @param  list<array{id: int, saldo: int}>  $cuotas  Con saldo, de la más antigua a la más reciente (en centavos)
     * @return array{aplicaciones: list<array{cuota_id: int, monto: int, ajuste: int}>, sobrante: int}
     */
    public function calcular(array $cuotas, int $disponible, int $tolerancia): array
    {
        $aplicaciones = [];

        foreach ($cuotas as $cuota) {
            if ($disponible <= 0) {
                break;
            }
            if ($disponible >= $cuota['saldo']) {
                $aplicaciones[] = ['cuota_id' => $cuota['id'], 'monto' => $cuota['saldo'], 'ajuste' => 0];
                $disponible -= $cuota['saldo'];

                continue;
            }
            if ($tolerancia >= $cuota['saldo'] - $disponible) {
                $aplicaciones[] = ['cuota_id' => $cuota['id'], 'monto' => $disponible, 'ajuste' => $cuota['saldo'] - $disponible];
                $disponible = 0;
            }
            break;
        }

        return ['aplicaciones' => $aplicaciones, 'sobrante' => $disponible];
    }

    /**
     * Qué pasaría al aprobar el pago con ese dinero recibido (no escribe nada).
     *
     * @return array{aplicaciones: list<array{cuota_id: int, monto: int, ajuste: int}>, sobrante: int, credito_previo: int}
     */
    public function simular(Pago $pago, int $recibido, int $tolerancia): array
    {
        $credito = Dinero::centavos($this->saldoFavor->de($pago->unidad_id));
        $r = $this->calcular($this->cuotasConSaldo($pago->unidad_id, false), $recibido + $credito, $tolerancia);

        return $r + ['credito_previo' => $credito];
    }

    /**
     * Aplica el pago: guarda las aplicaciones y suma lo pagado a cada cuota. Dentro de la
     * transacción de la aprobación; bloquea las cuotas de la unidad.
     *
     * @return array{aplicaciones: list<array{cuota_id: int, monto: int, ajuste: int}>, sobrante: int, credito_previo: int}
     */
    public function aplicar(Pago $pago, int $recibido, int $tolerancia): array
    {
        $credito = Dinero::centavos($this->saldoFavor->de($pago->unidad_id));
        $r = $this->calcular($this->cuotasConSaldo($pago->unidad_id, true), $recibido + $credito, $tolerancia);

        $ahora = now();
        foreach ($r['aplicaciones'] as $a) {
            DB::table('pago_aplicaciones')->insert([
                'condominio_id' => $pago->condominio_id, 'pago_id' => $pago->id, 'cuota_id' => $a['cuota_id'],
                'monto' => Dinero::texto($a['monto']), 'ajuste' => Dinero::texto($a['ajuste']),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('cuotas')->where('id', $a['cuota_id'])->update([
                'pagado' => DB::raw('pagado + '.Dinero::texto($a['monto'] + $a['ajuste'])),
                'updated_at' => $ahora,
            ]);
        }

        return $r + ['credito_previo' => $credito];
    }

    /**
     * @return list<array{id: int, saldo: int}>
     */
    private function cuotasConSaldo(int $unidadId, bool $bloquear): array
    {
        $consulta = Cuota::query()->where('unidad_id', $unidadId)->whereColumn('monto', '>', 'pagado')->orderBy('periodo')->orderBy('id');
        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        return $consulta->get(['id', 'monto', 'pagado'])
            ->map(fn (Cuota $c): array => ['id' => $c->id, 'saldo' => Dinero::centavos($c->monto) - Dinero::centavos($c->pagado)])
            ->all();
    }
}
