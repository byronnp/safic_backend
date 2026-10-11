<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\EstadoCuentaBancario;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Services\ConciliacionService;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: deshacer la conciliación de una línea. Vuelve a clasificarse desde cero (puede
 * quedar sugerida, automática por una regla o sin identificar) y libera el pago que tenía.
 */
final class DeshacerMovimientoAction
{
    public function __construct(
        private readonly ConciliacionService $conciliador,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    public function execute(int $movimientoId): MovimientoBancario
    {
        return DB::transaction(function () use ($movimientoId): MovimientoBancario {
            $m = MovimientoBancario::query()->lockForUpdate()->findOrFail($movimientoId);
            $this->periodoAbierto->exigir($m->fecha->toDateString());

            if (! $m->resuelto()) {
                throw new ApiException('MOVIMIENTO_PENDIENTE', 'Esta línea todavía no está conciliada.', 409);
            }

            $estado = EstadoCuentaBancario::query()->findOrFail($m->estado_cuenta_id);
            $m->forceFill(['estado' => MovimientoBancario::SIN_IDENTIFICAR, 'pago_id' => null, 'pago_proveedor_id' => null, 'tipo' => null])->save();
            $this->conciliador->clasificar(collect([$m]), $m->cuenta_bancaria_id, $estado->periodo->toImmutable());

            return $m->refresh();
        });
    }
}
