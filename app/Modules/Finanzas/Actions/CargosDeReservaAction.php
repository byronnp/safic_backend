<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Contrato público de Finanzas para Reservas: cobrar el uso y la garantía de una reserva en la cuenta de la
 * unidad (como cuota extraordinaria del mes), anular lo que nadie pagó y saber cuánto falta por pagar.
 * No sirve en un mes cerrado.
 */
final class CargosDeReservaAction
{
    public function __construct(private readonly Calendario $calendario, private readonly PeriodoAbierto $periodoAbierto) {}

    /** Crea la cuota y devuelve su id. El detalle debe ser único en la unidad y el mes (lleva el número de reserva). */
    public function cobrar(int $unidadId, string $detalle, string $monto, string $venceEl): int
    {
        $hoy = $this->calendario->hoy();
        $this->periodoAbierto->exigir($hoy);

        return Cuota::create([
            'unidad_id' => $unidadId, 'periodo' => CarbonImmutable::parse($hoy)->startOfMonth()->toDateString(), 'concepto' => 'extraordinaria',
            'detalle' => mb_substr($detalle, 0, 80), 'monto' => Dinero::texto(Dinero::centavos($monto)), 'pagado' => '0.00', 'vence_el' => max($venceEl, $hoy),
        ])->id;
    }

    /**
     * Borra las cuotas que nadie ha pagado ni tienen un pago en revisión. Las demás se quedan: la administración las resuelve.
     *
     * @param  list<int>  $cuotaIds
     */
    public function anularSinPagar(array $cuotaIds): void
    {
        foreach ($cuotaIds as $id) {
            DB::transaction(function () use ($id): void {
                $cuota = Cuota::query()->lockForUpdate()->find($id);
                if ($cuota !== null && Dinero::centavos((string) $cuota->pagado) === 0 && ! DB::table('pago_aplicaciones')->where('cuota_id', $id)->exists()) {
                    $cuota->delete();
                }
            });
        }
    }

    /**
     * Lo que falta pagar de esas cuotas, en centavos.
     *
     * @param  list<int>  $cuotaIds
     */
    public function saldoCentavos(array $cuotaIds): int
    {
        if ($cuotaIds === []) {
            return 0;
        }

        return Cuota::query()->whereIn('id', $cuotaIds)->get()->sum(fn (Cuota $c): int => max(0, Dinero::centavos((string) $c->monto) - Dinero::centavos((string) $c->pagado)));
    }
}
