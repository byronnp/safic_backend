<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Support\Dinero;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\GarantiaDeReserva;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta de la administración: las garantías de las reservas ya recibidas que piden atención — las que esperan la decisión de
 * los daños, las que hay que devolver y las ya devueltas — con cuánto hay que devolver en total. Lo que no hay que devolver
 * (sin garantía, sin pagar o consumida por el daño) no aparece.
 */
final class ListarGarantiasDeReservasAction
{
    public function __construct(private readonly GarantiaDeReserva $garantias) {}

    /** @return array{por_devolver: int, total_por_devolver: string, garantias: list<array<string, mixed>>} */
    public function execute(): array
    {
        $reservas = Reserva::query()->where('estado', Reserva::FINALIZADA)->where('garantia', '>', 0)->orderByDesc('entrega_en')->limit(200)->get();
        $pagadas = $this->garantias->pagadas($reservas);
        $areas = CondominioAmenidad::query()->whereIn('id', $reservas->pluck('amenidad_id'))->pluck('nombre', 'id');
        $unidades = Unidad::query()->whereIn('id', $reservas->pluck('unidad_id'))->pluck('codigo', 'id');

        $filas = [];
        foreach ($reservas as $r) {
            $g = $this->garantias->de($r, $pagadas[$r->id]);
            if ($g === null || $g['estado'] === GarantiaDeReserva::SIN_DEVOLUCION) {
                continue;
            }
            $filas[] = [
                'reserva_id' => $r->id, 'area' => (string) ($areas[$r->amenidad_id] ?? 'Área común'), 'unidad' => (string) ($unidades[$r->unidad_id] ?? ''),
                'fecha' => $r->fecha->toDateString(), 'garantia' => (string) $r->garantia, 'estado' => $g['estado'],
                'monto' => Dinero::texto($g['monto']),
                'devolucion' => $g['estado'] === GarantiaDeReserva::DEVUELTA ? [
                    'forma' => $r->garantia_devuelta_forma, 'fecha' => $r->garantia_devuelta_en?->toDateString(), 'referencia' => $r->garantia_devuelta_referencia,
                ] : null,
            ];
        }

        // Primero lo que espera algo de la administración, luego lo ya devuelto (cada grupo, lo más reciente primero)
        usort($filas, fn (array $a, array $b): int => [$a['estado'] === GarantiaDeReserva::DEVUELTA, -$a['reserva_id']] <=> [$b['estado'] === GarantiaDeReserva::DEVUELTA, -$b['reserva_id']]);
        $porDevolver = array_filter($filas, fn (array $f): bool => $f['estado'] === GarantiaDeReserva::POR_DEVOLVER);

        return [
            'por_devolver' => count($porDevolver),
            'total_por_devolver' => Dinero::texto(array_sum(array_map(fn (array $f): int => Dinero::centavos($f['monto']), $porDevolver))),
            'garantias' => $filas,
        ];
    }
}
