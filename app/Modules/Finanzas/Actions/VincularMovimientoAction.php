<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: confirmar que una línea del banco es un pago concreto (de un residente, si es un
 * crédito; a un proveedor, si es un débito). El monto debe ser el mismo y un pago se concilia con
 * una sola línea.
 */
final class VincularMovimientoAction
{
    public function __construct(private readonly PeriodoAbierto $periodoAbierto) {}

    public function execute(User $user, int $movimientoId, string $tipo, int $pagoId): MovimientoBancario
    {
        try {
            return DB::transaction(function () use ($user, $movimientoId, $tipo, $pagoId): MovimientoBancario {
                $m = MovimientoBancario::query()->lockForUpdate()->findOrFail($movimientoId);
                $this->periodoAbierto->exigir($m->fecha->toDateString());

                if ($m->estado === MovimientoBancario::CONCILIADO || $m->estado === MovimientoBancario::AUTOMATICO) {
                    throw new ApiException('MOVIMIENTO_RESUELTO', 'Esta línea ya está conciliada. Desházla primero si quieres cambiarla.', 409);
                }

                $centavos = Dinero::centavos($m->monto);

                if ($tipo === 'pago_residente') {
                    if ($centavos <= 0) {
                        throw new ApiException('DIRECCION_INVALIDA', 'Un pago de residente corresponde a un crédito (dinero que entra).', 422);
                    }
                    $pago = Pago::query()->where('estado', Pago::APROBADO)->find($pagoId)
                        ?? throw new ApiException('PAGO_NO_ENCONTRADO', 'Ese pago no existe o no está aprobado.', 404);
                    $montoPago = Dinero::centavos($pago->monto);
                    $campos = ['pago_id' => $pago->id];
                } elseif ($tipo === 'pago_proveedor') {
                    if ($centavos >= 0) {
                        throw new ApiException('DIRECCION_INVALIDA', 'Un pago a proveedor corresponde a un débito (dinero que sale).', 422);
                    }
                    $pago = PagoProveedor::query()->where('estado', 'registrado')->find($pagoId)
                        ?? throw new ApiException('PAGO_NO_ENCONTRADO', 'Ese pago no existe o está anulado.', 404);
                    $montoPago = Dinero::centavos($pago->monto);
                    $campos = ['pago_proveedor_id' => $pago->id];
                } else {
                    throw new ApiException('TIPO_INVALIDO', 'Elige si es un pago de residente o a un proveedor.', 422);
                }

                if ($montoPago !== abs($centavos)) {
                    throw new ApiException('MONTO_DISTINTO', 'El monto del pago ('.Dinero::texto($montoPago).') no es el de la línea del banco ('.Dinero::texto(abs($centavos)).').', 422);
                }

                $m->forceFill($campos + [
                    'estado' => MovimientoBancario::CONCILIADO, 'tipo' => $tipo,
                    'propuesta_tipo' => null, 'propuesta_id' => null, 'propuesta_texto' => null,
                    'conciliado_por' => $user->id, 'conciliado_en' => now(),
                ])->save();

                return $m;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('PAGO_YA_CONCILIADO', 'Ese pago ya está conciliado con otra línea del banco.', 409);
        }
    }
}
