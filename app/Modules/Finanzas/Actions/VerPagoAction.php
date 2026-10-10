<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\AplicacionPagoService;
use App\Modules\Finanzas\Services\ToleranciaBancaria;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;

/**
 * Consulta de la administración: un pago con su comprobante (enlace temporal), las validaciones
 * que se le hacen y qué pasaría al aprobarlo (a cuántas cuotas se aplica y cuánto queda a favor).
 */
final class VerPagoAction
{
    public function __construct(
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ArchivosCondominio $archivos,
        private readonly AplicacionPagoService $aplicacion,
        private readonly ToleranciaBancaria $tolerancia,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $id): array
    {
        $pago = Pago::query()->findOrFail($id);
        $cuenta = $pago->cuenta_bancaria_id === null ? null : CuentaBancaria::query()->find($pago->cuenta_bancaria_id);
        $pagador = $pago->user_id === null ? null : User::query()->find($pago->user_id);
        $tolerancia = $this->tolerancia->centavos();

        $simulacion = $pago->estado === Pago::PENDIENTE
            ? $this->aplicacion->simular($pago, Dinero::centavos($pago->monto), $tolerancia)
            : null;

        $comprobante = $pago->comprobante_ruta !== null && $this->archivos->existe($pago->comprobante_ruta)
            ? $this->archivos->urlTemporal($pago->comprobante_ruta)
            : null;

        return [
            'id' => $pago->id,
            'unidad_id' => $pago->unidad_id,
            'unidad' => $this->codigos->execute([$pago->unidad_id])[$pago->unidad_id] ?? '—',
            'pagador' => $pagador?->name,
            'metodo' => $pago->metodo,
            'monto' => (string) $pago->monto,
            'diferencia' => (string) $pago->diferencia,
            'fecha' => $pago->fecha_pago->toDateString(),
            'numero_comprobante' => $pago->numero_comprobante,
            'banco_origen' => $pago->banco_origen,
            'cuenta_destino' => $cuenta === null ? null : $cuenta->banco.' · '.$cuenta->tipo,
            'estado' => $pago->estado,
            'motivo_rechazo' => $pago->motivo_rechazo,
            'recibo' => $pago->recibo_numero === null ? null : ListarPagosAction::numeroRecibo($pago->recibo_numero),
            'comprobante_url' => $comprobante,
            'comprobante_tipo' => $pago->comprobante_ruta === null ? null : strtolower(pathinfo($pago->comprobante_ruta, PATHINFO_EXTENSION)),
            'cuotas' => $pago->cuotas_declaradas ?? [],
            'tolerancia' => Dinero::texto($tolerancia),
            'validaciones' => $simulacion === null ? [] : $this->validaciones($pago, $simulacion),
            'aplicacion' => $simulacion === null ? null : [
                'cuotas_completas' => count($simulacion['aplicaciones']),
                'ajuste' => Dinero::texto(array_sum(array_column($simulacion['aplicaciones'], 'ajuste'))),
                'a_saldo_favor' => Dinero::texto($simulacion['sobrante']),
                'credito_previo' => Dinero::texto($simulacion['credito_previo']),
            ],
        ];
    }

    /**
     * Las comprobaciones que ve la persona que aprueba.
     *
     * @param  array{aplicaciones: list<array{cuota_id: int, monto: int, ajuste: int}>, sobrante: int, credito_previo: int}  $simulacion
     * @return list<array{clave: string, ok: bool, texto: string}>
     */
    public function validaciones(Pago $pago, array $simulacion): array
    {
        $declaradas = array_column($pago->cuotas_declaradas ?? [], 'cuota_id');
        $pendientes = Cuota::query()->whereIn('id', $declaradas)->whereColumn('monto', '>', 'pagado')->count();
        $cubiertas = count(array_intersect($declaradas, array_column($simulacion['aplicaciones'], 'cuota_id')));

        return [
            [
                'clave' => 'cuotas_vigentes', 'ok' => $pendientes === count($declaradas),
                'texto' => $pendientes === count($declaradas) ? 'Las cuotas declaradas siguen pendientes' : 'Alguna cuota declarada ya no está pendiente',
            ],
            [
                'clave' => 'monto_cubre', 'ok' => $cubiertas === count($declaradas),
                'texto' => $cubiertas === count($declaradas) ? 'El monto cubre las cuotas declaradas' : 'El monto no alcanza para todas las cuotas declaradas',
            ],
            ['clave' => 'comprobante_unico', 'ok' => true, 'texto' => 'Número de comprobante no repetido'],
            [
                'clave' => 'fecha_valida', 'ok' => $pago->fecha_pago->toDateString() <= $this->calendario->hoy(),
                'texto' => $pago->fecha_pago->toDateString() <= $this->calendario->hoy() ? 'La fecha del pago es válida' : 'La fecha del pago es futura',
            ],
        ];
    }
}
