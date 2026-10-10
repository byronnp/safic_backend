<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Support\SequenceService;
use App\Models\User;
use App\Modules\Finanzas\Mail\PagoAprobadoMail;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\AplicacionPagoService;
use App\Modules\Finanzas\Services\ToleranciaBancaria;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso de la administración: aprobar un pago. Lo recibido (por defecto lo declarado, o el
 * monto que la persona confirma contra el banco) más el saldo a favor se aplica a las cuotas
 * completas de la más antigua en adelante; lo que sobra queda a favor. Se numera el recibo y se
 * avisa al residente. Todo o nada, y un pago ya revisado no se vuelve a aprobar.
 */
final class AprobarPagoAction
{
    public function __construct(
        private readonly AplicacionPagoService $aplicacion,
        private readonly ToleranciaBancaria $tolerancia,
        private readonly SequenceService $secuencias,
        private readonly CodigosDeUnidadesAction $codigos,
    ) {}

    public function execute(int $id, ?string $montoRecibido, User $actor): Pago
    {
        $pago = DB::transaction(function () use ($id, $montoRecibido, $actor): Pago {
            $pago = Pago::query()->lockForUpdate()->findOrFail($id);

            if ($pago->estado !== Pago::PENDIENTE) {
                throw new ApiException('PAGO_NO_PENDIENTE', 'Este pago ya fue revisado.', 409);
            }

            $declarado = Dinero::centavos($pago->monto);
            $recibido = $montoRecibido === null ? $declarado : Dinero::centavos($montoRecibido);
            if ($recibido <= 0) {
                throw new ApiException('MONTO_INVALIDO', 'El monto recibido debe ser mayor a cero.', 422);
            }

            $this->aplicacion->aplicar($pago, $recibido, $this->tolerancia->centavos());

            $pago->forceFill([
                'estado' => Pago::APROBADO,
                'monto' => Dinero::texto($recibido),
                'diferencia' => Dinero::texto($recibido - $declarado),
                'revisado_por' => $actor->id,
                'revisado_en' => now(),
                'recibo_numero' => $this->secuencias->siguiente('recibos'),
            ])->save();

            return $pago;
        });

        $this->avisar($pago);

        return $pago;
    }

    private function avisar(Pago $pago): void
    {
        $pagador = $pago->user_id === null ? null : User::query()->find($pago->user_id);
        if ($pagador === null || $pagador->email === '') {
            return;
        }

        Mail::to($pagador->email)->queue(new PagoAprobadoMail(
            nombre: $pagador->name,
            condominio: Condominio::query()->findOrFail($pago->condominio_id)->nombre,
            unidad: $this->codigos->execute([$pago->unidad_id])[$pago->unidad_id] ?? '',
            monto: (string) $pago->monto,
            recibo: ListarPagosAction::numeroRecibo((int) $pago->recibo_numero),
            meses: array_column($pago->cuotas_declaradas ?? [], 'periodo'),
        ));
    }
}
