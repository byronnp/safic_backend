<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Support\SequenceService;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\CajaChica;
use App\Modules\Finanzas\Models\CajaChicaMovimiento;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: reponer el fondo. Los movimientos abiertos se agrupan en un gasto «CC-aaaa-nnn» a nombre
 * del proveedor «Caja chica» (a quien se le paga la reposición, o sea, a quien responde por el fondo) y
 * ese gasto sigue el flujo normal: aprobación (quien lo registra no lo aprueba), pago del tesorero y
 * conciliación. Para pagarlo, ese proveedor necesita su cuenta bancaria como cualquier otro.
 */
final class ReponerCajaChicaAction
{
    /** RUC de «consumidor final»: el proveedor interno de la caja chica no es una empresa real. */
    public const RUC_PROVEEDOR = '9999999999999';

    public function __construct(
        private readonly SequenceService $secuencias,
        private readonly Calendario $calendario,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    public function execute(User $user): Gasto
    {
        $hoy = $this->calendario->hoy();
        $this->periodoAbierto->exigir($hoy);

        return DB::transaction(function () use ($user, $hoy): Gasto {
            $caja = CajaChica::query()->lockForUpdate()->first()
                ?? throw new ApiException('CAJA_SIN_CONFIGURAR', 'La caja chica aún no tiene fondo.', 409);

            $movimientos = CajaChicaMovimiento::query()->where('estado', CajaChicaMovimiento::ABIERTO)->lockForUpdate()->get();
            if ($movimientos->isEmpty()) {
                throw new ApiException('SIN_MOVIMIENTOS', 'No hay gastos por reponer desde la última reposición.', 409);
            }

            $total = Dinero::suma($movimientos->map(fn (CajaChicaMovimiento $m) => (string) $m->monto));
            $proveedor = Proveedor::query()->where('ruc', self::RUC_PROVEEDOR)->first()
                ?? Proveedor::create(['ruc' => self::RUC_PROVEEDOR, 'razon_social' => 'Caja chica · '.$caja->responsable_nombre, 'categoria' => 'Caja chica', 'activo' => true]);
            if ($proveedor->razon_social !== 'Caja chica · '.$caja->responsable_nombre) {
                $proveedor->forceFill(['razon_social' => 'Caja chica · '.$caja->responsable_nombre])->save();
            }

            $anio = CarbonImmutable::parse($hoy)->year;
            $gasto = Gasto::create([
                'proveedor_id' => $proveedor->id,
                'numero' => sprintf('CC-%d-%03d', $anio, $this->secuencias->siguiente('caja_chica')),
                'origen' => 'caja_chica', 'categoria' => 'Caja chica',
                'descripcion' => 'Reposición de caja chica ('.$movimientos->count().($movimientos->count() === 1 ? ' movimiento)' : ' movimientos)'),
                'fecha_emision' => $hoy, 'vence_el' => CarbonImmutable::parse($hoy)->addDays(7)->toDateString(),
                'subtotal' => Dinero::texto($total), 'iva' => '0.00', 'total' => Dinero::texto($total), 'pagado' => '0.00',
                'estado' => 'por_aprobar', 'registrado_por' => $user->id,
            ]);

            CajaChicaMovimiento::query()->whereIn('id', $movimientos->pluck('id'))->update(['estado' => CajaChicaMovimiento::REPUESTO, 'gasto_id' => $gasto->id]);

            return $gasto;
        });
    }
}
