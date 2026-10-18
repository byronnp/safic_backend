<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Garita\Models\SolicitudIngreso;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: retirar su pregunta (el visitante se fue, o ya resolvió por teléfono). Sirve también para quitar de la lista una
 * solicitud rechazada o vencida.
 */
final class CancelarSolicitudIngresoAction
{
    public function execute(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $s = SolicitudIngreso::query()->lockForUpdate()->findOrFail($id);
            if ($s->estado === SolicitudIngreso::USADA) {
                throw new ApiException('SOLICITUD_YA_USADA', 'Este ingreso ya se registró.', 409);
            }
            $s->forceFill(['estado' => SolicitudIngreso::CANCELADA])->save();
        });
    }
}
