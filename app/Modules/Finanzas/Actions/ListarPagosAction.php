<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Services\AplicacionPagoService;
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
        private readonly VerPagoAction $ver,
        private readonly AplicacionPagoService $aplicacion,
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
                'pagador' => $p->user_id === null ? $p->pagador_nombre : ($usuarios[$p->user_id] ?? null),
                'banco' => $p->cuenta_bancaria_id === null ? $p->banco_origen : ($cuentas[$p->cuenta_bancaria_id] ?? $p->banco_origen),
                'metodo' => $p->metodo,
                'monto' => (string) $p->monto,
                'fecha' => $p->fecha_pago->toDateString(),
                'numero_comprobante' => $p->numero_comprobante,
                'cuotas' => array_column($p->cuotas_declaradas ?? [], 'periodo'),
                'estado' => $p->estado,
                'motivo_rechazo' => $p->motivo_rechazo,
                'recibo' => $p->recibo_numero === null ? null : self::numeroRecibo($p->recibo_numero),
                'validacion' => $p->estado === Pago::PENDIENTE ? $this->validacion($p) : null,
            ])->all(),
            'conteos' => $conteos,
            'tolerancia' => Dinero::texto($this->tolerancia->centavos()),
        ];
    }

    /**
     * Resumen de las validaciones de un pago pendiente para la columna de la lista.
     *
     * @return array{nivel: string, texto: string}
     */
    private function validacion(Pago $pago): array
    {
        $simulacion = $this->aplicacion->simular($pago, Dinero::centavos($pago->monto), $this->tolerancia->centavos());
        $fallida = collect($this->ver->validaciones($pago, $simulacion))->first(fn (array $v) => ! $v['ok']);

        return match ($fallida['clave'] ?? null) {
            null => ['nivel' => 'ok', 'texto' => 'Todo coincide'],
            'monto_cubre' => ['nivel' => 'error', 'texto' => 'Monto no cubre'],
            'cuotas_vigentes' => ['nivel' => 'error', 'texto' => 'Cuotas cambiaron'],
            default => ['nivel' => 'error', 'texto' => 'Revisar'],
        };
    }

    public static function numeroRecibo(int $numero): string
    {
        return str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
