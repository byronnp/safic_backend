<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Mail\PagoRechazadoMail;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso de la administración: rechazar un comprobante con el motivo. Las cuotas quedan libres
 * (ya no van «en revisión») y el número de comprobante se puede volver a usar. Se avisa al residente.
 */
final class RechazarPagoAction
{
    public function __construct(private readonly CodigosDeUnidadesAction $codigos) {}

    public function execute(int $id, string $motivo, User $actor): Pago
    {
        $pago = DB::transaction(function () use ($id, $motivo, $actor): Pago {
            $pago = Pago::query()->lockForUpdate()->findOrFail($id);

            if ($pago->estado !== Pago::PENDIENTE) {
                throw new ApiException('PAGO_NO_PENDIENTE', 'Este pago ya fue revisado.', 409);
            }

            $pago->forceFill([
                'estado' => Pago::RECHAZADO,
                'motivo_rechazo' => $motivo,
                'revisado_por' => $actor->id,
                'revisado_en' => now(),
            ])->save();

            return $pago;
        });

        $pagador = $pago->user_id === null ? null : User::query()->find($pago->user_id);
        if ($pagador !== null && $pagador->email !== '') {
            Mail::to($pagador->email)->queue(new PagoRechazadoMail(
                nombre: $pagador->name,
                condominio: Condominio::query()->findOrFail($pago->condominio_id)->nombre,
                unidad: $this->codigos->execute([$pago->unidad_id])[$pago->unidad_id] ?? '',
                monto: (string) $pago->monto,
                motivo: $motivo,
            ));
        }

        return $pago;
    }
}
