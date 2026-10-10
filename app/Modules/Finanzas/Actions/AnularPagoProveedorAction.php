<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\PagoProveedor;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: anular un pago a proveedor (por ejemplo, una transferencia mal registrada).
 * Exige motivo, devuelve el saldo a las facturas y deja constancia de quién y cuándo.
 */
final class AnularPagoProveedorAction
{
    public function execute(User $user, int $pagoId, string $motivo): PagoProveedor
    {
        return DB::transaction(function () use ($user, $pagoId, $motivo): PagoProveedor {
            $pago = PagoProveedor::query()->lockForUpdate()->findOrFail($pagoId);

            if ($pago->estado !== 'registrado') {
                throw new ApiException('PAGO_YA_ANULADO', 'Este pago ya está anulado.', 409);
            }

            foreach ($pago->aplicaciones()->get() as $a) {
                $g = Gasto::query()->lockForUpdate()->findOrFail($a->gasto_id);
                $g->pagado = Dinero::texto(Dinero::centavos($g->pagado) - Dinero::centavos($a->monto));
                if ($g->estado === 'pagada') {
                    $g->estado = 'aprobada';
                }
                $g->save();
            }

            $pago->forceFill([
                'estado' => 'anulado', 'motivo_anulacion' => $motivo, 'anulado_por' => $user->id, 'anulado_en' => now(),
            ])->save();

            return $pago;
        });
    }
}
