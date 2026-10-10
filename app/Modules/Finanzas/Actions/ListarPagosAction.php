<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\ToleranciaBancaria;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;

/**
 * Consulta de la administración (Pagos por aprobar): los pagos de los residentes por estado,
 * con cuántos hay en cada uno y la tolerancia bancaria vigente.
 */
final class ListarPagosAction
{
    public function __construct(
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ToleranciaBancaria $tolerancia,
    ) {}

    /**
     * @return array{pagos: list<array<string, mixed>>, conteos: array<string, int>, tolerancia: string}
     */
    public function execute(string $estado): array
    {
        $pagos = Pago::query()
            ->where('estado', $estado)
            ->orderBy($estado === Pago::PENDIENTE ? 'id' : 'revisado_en', $estado === Pago::PENDIENTE ? 'asc' : 'desc')
            ->limit(200)
            ->get();

        $unidades = $this->codigos->execute($pagos->pluck('unidad_id')->all());
        $usuarios = User::query()->whereIn('id', $pagos->pluck('user_id')->filter()->all())->pluck('name', 'id');
        $cuentas = CuentaBancaria::query()->whereIn('id', $pagos->pluck('cuenta_bancaria_id')->filter()->all())->pluck('banco', 'id');

        $conteos = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0];
        foreach (Pago::query()->selectRaw('estado, count(*) as total')->whereIn('estado', array_keys($conteos))->groupBy('estado')->pluck('total', 'estado') as $e => $total) {
            $conteos[$e] = (int) $total;
        }

        return [
            'pagos' => $pagos->map(fn (Pago $p): array => [
                'id' => $p->id,
                'unidad_id' => $p->unidad_id,
                'unidad' => $unidades[$p->unidad_id] ?? '—',
                'pagador' => $p->user_id === null ? null : ($usuarios[$p->user_id] ?? null),
                'banco' => $p->cuenta_bancaria_id === null ? $p->banco_origen : ($cuentas[$p->cuenta_bancaria_id] ?? $p->banco_origen),
                'metodo' => $p->metodo,
                'monto' => (string) $p->monto,
                'fecha' => $p->fecha_pago->toDateString(),
                'numero_comprobante' => $p->numero_comprobante,
                'cuotas' => array_column($p->cuotas_declaradas ?? [], 'periodo'),
                'estado' => $p->estado,
                'motivo_rechazo' => $p->motivo_rechazo,
                'recibo' => $p->recibo_numero === null ? null : self::numeroRecibo($p->recibo_numero),
            ])->all(),
            'conteos' => $conteos,
            'tolerancia' => Dinero::texto($this->tolerancia->centavos()),
        ];
    }

    public static function numeroRecibo(int $numero): string
    {
        return str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
