<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\SaldoFavorService;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;

/**
 * Consulta del residente (pantalla Mi cuenta): por cada unidad que ocupa, lo que debe, lo que
 * está en revisión, su saldo a favor, sus pagos y la cuenta bancaria a la que transfiere.
 * Solo ve las unidades de su propia cuenta (UnidadesDeCuentaAction).
 */
final class ObtenerMiCuentaAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly SaldoFavorService $saldoFavor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $condominio = Condominio::query()->findOrFail($this->tenant->require());
        $hoy = $this->calendario->hoy();
        $cuenta = CuentaBancaria::query()->where('activa', true)->where('es_principal', true)->first();

        $unidades = array_map(fn (array $u): array => $this->unidad($u, $condominio, $hoy), $this->unidades->execute($user->id));

        return [
            'condominio' => $condominio->nombre,
            'unidades' => $unidades,
            'cuenta_bancaria' => $cuenta === null ? null : [
                'banco' => $cuenta->banco, 'tipo' => $cuenta->tipo, 'numero' => $cuenta->numero, 'titular' => $cuenta->titular,
            ],
        ];
    }

    /**
     * @param  array{unidad_id: int, codigo: string, relacion: string, puede_pagar: bool}  $u
     * @return array<string, mixed>
     */
    private function unidad(array $u, Condominio $condominio, string $hoy): array
    {
        $pagos = Pago::query()->where('unidad_id', $u['unidad_id'])->orderByDesc('id')->limit(10)->get();
        $enRevision = self::cuotasEnRevision($u['unidad_id']);

        $cuotas = Cuota::query()
            ->where('unidad_id', $u['unidad_id'])
            ->whereColumn('monto', '>', 'pagado')
            ->orderBy('periodo')->orderBy('id')
            ->get()
            ->map(function (Cuota $c) use ($enRevision, $hoy): array {
                $saldo = Dinero::centavos($c->monto) - Dinero::centavos($c->pagado);
                $vence = $c->vence_el->toDateString();

                return [
                    'id' => $c->id,
                    'periodo' => $c->periodo->format('Y-m'),
                    'concepto' => $c->concepto,
                    'monto' => (string) $c->monto,
                    'saldo' => Dinero::texto($saldo),
                    'vence_el' => $vence,
                    'estado' => match (true) {
                        in_array($c->id, $enRevision, true) => 'en_revision',
                        $vence < $hoy => 'vencida',
                        default => 'pendiente',
                    },
                ];
            })->values()->all();

        return [
            'unidad_id' => $u['unidad_id'],
            'codigo' => $u['codigo'],
            'relacion' => $u['relacion'],
            'puede_pagar' => $u['puede_pagar'],
            'encabezado' => $condominio->nombre.' · '.$u['codigo'],
            // Lo que escribe en el concepto de la transferencia: JV-A102 (sin guiones del código de la unidad)
            'concepto_transferencia' => $condominio->codigo.'-'.preg_replace('/[\s-]+/', '', $u['codigo']),
            'total_pendiente' => Dinero::texto(Dinero::suma(array_column($cuotas, 'saldo'))),
            'vencidas' => count(array_filter($cuotas, fn (array $c) => $c['estado'] === 'vencida')),
            'saldo_favor' => $this->saldoFavor->de($u['unidad_id']),
            'cuotas' => $cuotas,
            'pagos' => $pagos->map(fn (Pago $p): array => [
                'id' => $p->id,
                'monto' => (string) $p->monto,
                'estado' => $p->estado,
                'fecha' => $p->fecha_pago->toDateString(),
                'numero_comprobante' => $p->numero_comprobante,
                'motivo_rechazo' => $p->estado === Pago::RECHAZADO ? $p->motivo_rechazo : null,
                'cuotas' => array_column($p->cuotas_declaradas ?? [], 'periodo'),
            ])->all(),
        ];
    }

    /**
     * Ids de las cuotas que el residente ya declaró en un pago que espera revisión.
     *
     * @return list<int>
     */
    public static function cuotasEnRevision(int $unidadId): array
    {
        $ids = [];
        foreach (Pago::query()->where('unidad_id', $unidadId)->where('estado', Pago::PENDIENTE)->get(['cuotas_declaradas']) as $p) {
            foreach ($p->cuotas_declaradas ?? [] as $c) {
                $ids[] = (int) $c['cuota_id'];
            }
        }

        return $ids;
    }
}
