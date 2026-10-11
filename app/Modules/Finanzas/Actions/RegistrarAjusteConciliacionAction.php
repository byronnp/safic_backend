<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\AjusteConciliacion;
use App\Modules\Finanzas\Models\EstadoCuentaBancario;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: justificar con un motivo una diferencia entre el banco y la app (por ejemplo, un
 * cobro del banco que no se va a identificar). El ajuste suma o resta al saldo de la app y queda
 * auditado.
 */
final class RegistrarAjusteConciliacionAction
{
    public function __construct(private readonly PeriodoAbierto $periodoAbierto) {}

    public function execute(User $user, int $estadoCuentaId, string $monto, string $motivo): AjusteConciliacion
    {
        return DB::transaction(function () use ($user, $estadoCuentaId, $monto, $motivo): AjusteConciliacion {
            $estado = EstadoCuentaBancario::query()->lockForUpdate()->findOrFail($estadoCuentaId);
            $this->periodoAbierto->exigir($estado->periodo->toDateString());

            return AjusteConciliacion::create([
                'estado_cuenta_id' => $estado->id,
                'monto' => Dinero::texto(Dinero::centavos($monto)),
                'motivo' => $motivo,
                'user_id' => $user->id,
            ]);
        });
    }
}
