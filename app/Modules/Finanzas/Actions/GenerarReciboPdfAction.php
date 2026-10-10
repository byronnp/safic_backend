<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Privacy\DatosPersonales;
use App\Core\Support\Dinero;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Recibo de un pago aprobado en PDF (número consecutivo del condominio, unidad, pagador, cuotas
 * pagadas y valor). No es una factura electrónica. La cédula del pagador sale completa solo si
 * quien lo pide puede ver datos personales.
 */
final class GenerarReciboPdfAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CodigosDeUnidadesAction $codigos,
    ) {}

    /**
     * @param  list<int>|null  $unidadesPermitidas  Si se indica, el pago debe ser de una de esas unidades (el residente)
     * @return array{contenido: string, nombre: string}
     */
    public function execute(int $pagoId, bool $datosCompletos, ?array $unidadesPermitidas = null): array
    {
        $pago = Pago::query()->findOrFail($pagoId);

        if ($unidadesPermitidas !== null && ! in_array($pago->unidad_id, $unidadesPermitidas, true)) {
            // Para el residente, un pago de otra unidad no existe
            abort(404);
        }
        if ($pago->estado !== Pago::APROBADO || $pago->recibo_numero === null) {
            throw new ApiException('PAGO_SIN_RECIBO', 'Solo los pagos aprobados tienen recibo.', 409);
        }

        $condominio = Condominio::query()->findOrFail($this->tenant->require());
        $pagador = $pago->user_id === null ? null : User::query()->find($pago->user_id);
        $numero = ListarPagosAction::numeroRecibo($pago->recibo_numero);

        $aplicaciones = DB::table('pago_aplicaciones')->where('pago_id', $pago->id)->orderBy('cuota_id')->get();
        $cuotas = Cuota::query()->whereIn('id', $aplicaciones->pluck('cuota_id')->all())->get()->keyBy('id');
        $aplicado = 0;
        $detalle = [];
        foreach ($aplicaciones as $a) {
            $cuota = $cuotas[$a->cuota_id] ?? null;
            $aplicado += Dinero::centavos((string) $a->monto);
            $detalle[] = [
                'concepto' => $cuota?->concepto === 'extraordinaria' ? 'Cuota extraordinaria' : 'Cuota ordinaria',
                'mes' => $cuota === null ? '—' : $cuota->periodo->format('m/Y'),
                'monto' => Dinero::texto(Dinero::centavos((string) $a->monto) + Dinero::centavos((string) $a->ajuste)),
            ];
        }

        $cedula = $pagador?->cedula;
        $pdf = Pdf::loadView('recibos.pago', [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc, 'direccion' => $condominio->direccion],
            'numero' => $numero,
            'fecha' => ($pago->revisado_en ?? $pago->updated_at)->timezone('America/Guayaquil')->format('d/m/Y'),
            'unidad' => $this->codigos->execute([$pago->unidad_id])[$pago->unidad_id] ?? '—',
            'pagador' => [
                'nombre' => $pagador === null ? ($pago->pagador_nombre ?? '—') : $pagador->name,
                'cedula' => $cedula === null ? null : ($datosCompletos ? $cedula : DatosPersonales::enmascararDocumento($cedula)),
            ],
            'aplicaciones' => $detalle,
            'sobrante' => Dinero::texto(max(0, Dinero::centavos($pago->monto) - $aplicado)),
            'total' => (string) $pago->monto,
            'forma' => $pago->metodo === 'efectivo' ? 'Efectivo' : 'Transferencia bancaria',
            'comprobante' => $pago->numero_comprobante,
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => "recibo-{$numero}.pdf"];
    }
}
