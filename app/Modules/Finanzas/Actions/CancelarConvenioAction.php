<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\ConvenioPago;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: cancelar un convenio activo (por ejemplo, porque se incumplió). Exige motivo. Desde
 * ese momento la unidad vuelve a contar como deudora y puede ser notificada otra vez.
 */
final class CancelarConvenioAction
{
    public function execute(int $id, string $motivo): ConvenioPago
    {
        return DB::transaction(function () use ($id, $motivo): ConvenioPago {
            $c = ConvenioPago::query()->lockForUpdate()->findOrFail($id);

            if ($c->estado !== ConvenioPago::ACTIVO) {
                throw new ApiException('CONVENIO_NO_ACTIVO', 'Este convenio ya no está activo.', 409);
            }

            $c->forceFill(['estado' => ConvenioPago::CANCELADO, 'motivo_cancelacion' => $motivo])->save();

            return $c;
        });
    }
}
