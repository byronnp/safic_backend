<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;
use App\Modules\Unidades\Actions\UnidadEstadoCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración: registrar un pago en efectivo de una unidad. No hay comprobante que
 * revisar: lo recibe quien lo registra, así que se aprueba en el acto con las mismas reglas de
 * aplicación (cuotas completas desde la más antigua, tolerancia, saldo a favor) y sale su recibo.
 */
final class RegistrarPagoEfectivoAction
{
    public function __construct(
        private readonly UnidadEstadoCuentaAction $unidad,
        private readonly ResponsablesDePagoAction $responsables,
        private readonly AprobarPagoAction $aprobar,
        private readonly Calendario $calendario,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    public function execute(int $unidadId, string $monto, ?string $fecha, User $actor): Pago
    {
        $this->unidad->execute($unidadId) ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'Esa unidad no existe.', 404);

        $centavos = Dinero::centavos($monto);
        if ($centavos <= 0) {
            throw new ApiException('MONTO_INVALIDO', 'El monto debe ser mayor a cero.', 422);
        }
        $hoy = $this->calendario->hoy();
        $fecha ??= $hoy;
        if ($fecha > $hoy) {
            throw new ApiException('FECHA_FUTURA', 'La fecha del pago no puede ser futura.', 422);
        }

        $this->periodoAbierto->exigir($fecha);

        $pago = DB::transaction(fn (): Pago => Pago::create([
            'unidad_id' => $unidadId,
            'user_id' => null,
            'metodo' => 'efectivo',
            'monto' => Dinero::texto($centavos),
            'fecha_pago' => $fecha,
            'estado' => Pago::PENDIENTE,
            'pagador_nombre' => $this->responsables->execute([$unidadId])[$unidadId]['nombre'] ?? null,
        ]));

        return $this->aprobar->execute($pago->id, null, $actor);
    }
}
