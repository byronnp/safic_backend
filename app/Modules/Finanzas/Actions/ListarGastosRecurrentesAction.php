<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\RecurrenteOcurrencia;
use Carbon\CarbonImmutable;

/**
 * Consulta: los gastos recurrentes con lo que pasa en el mes elegido (programado, falta la
 * factura, factura recibida, pausado), lo comprometido al mes y cuál es el próximo.
 */
final class ListarGastosRecurrentesAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /**
     * @param  string  $periodo  YYYY-MM
     * @return array{recurrentes: list<array<string, mixed>>, resumen: array<string, mixed>}
     */
    public function execute(string $periodo): array
    {
        $hoy = $this->calendario->hoy();
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: CarbonImmutable::parse($hoy)->startOfMonth();

        $recurrentes = GastoRecurrente::query()->with('proveedor:id,razon_social')->orderBy('dia_mes')->orderBy('nombre')->get();
        $ocurrencias = RecurrenteOcurrencia::query()->where('periodo', $mes->toDateString())->get()->keyBy('gasto_recurrente_id');

        $filas = [];
        $comprometido = 0;
        $porAdjuntar = 0;
        $proximo = null;

        foreach ($recurrentes as $r) {
            $o = $ocurrencias->get($r->id);
            $fecha = $o?->fecha_prevista->toDateString() ?? $mes->day($r->dia_mes)->toDateString();
            $estado = match (true) {
                $o?->gasto_id !== null => 'factura_recibida',
                ! $r->activo => 'pausado',
                $fecha <= $hoy => 'falta_factura',
                default => 'programado',
            };

            if ($r->activo && $r->monto !== null) {
                $comprometido += Dinero::centavos((string) $r->monto);
            }
            if ($estado === 'falta_factura') {
                $porAdjuntar++;
            }
            if ($r->activo && $o?->gasto_id === null && $fecha >= $hoy && ($proximo === null || $fecha < $proximo['fecha'])) {
                $proximo = ['nombre' => $r->nombre, 'fecha' => $fecha];
            }

            $filas[] = [
                'id' => $r->id,
                'nombre' => $r->nombre,
                'proveedor' => ['id' => $r->proveedor_id, 'razon_social' => $r->proveedor->razon_social],
                'categoria' => $r->categoria,
                'monto' => $r->monto === null ? null : (string) $r->monto,
                'dia_mes' => $r->dia_mes,
                'activo' => $r->activo,
                'aprobacion_automatica' => $r->aprobacion_automatica,
                'mes' => [
                    'estado' => $estado,
                    'fecha_prevista' => $fecha,
                    'gasto_id' => $o?->gasto_id,
                    'aprobada_automaticamente' => (bool) $o?->aprobada_automaticamente,
                ],
            ];
        }

        return [
            'recurrentes' => $filas,
            'resumen' => ['comprometido' => Dinero::texto($comprometido), 'por_adjuntar' => $porAdjuntar, 'proximo' => $proximo],
        ];
    }
}
