<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Support\Dinero;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\GarantiaDeReserva;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta de la administración: las reservas con daños reportados, las que esperan decisión primero y luego las ya resueltas.
 * Trae lo justo para la lista; el detalle (con fotos) se pide aparte.
 */
final class ListarDanosDeReservasAction
{
    public function __construct(private readonly GarantiaDeReserva $garantias) {}

    /** @return array{pendientes: int, danos: list<array<string, mixed>>} */
    public function execute(): array
    {
        $reservas = Reserva::query()->whereNotNull('danos')->orderByRaw('danos_decision is not null')->orderByDesc('entrega_en')->limit(100)->get();
        $areas = CondominioAmenidad::query()->whereIn('id', $reservas->pluck('amenidad_id'))->pluck('nombre', 'id');
        $unidades = Unidad::query()->whereIn('id', $reservas->pluck('unidad_id'))->pluck('codigo', 'id');
        $pagadas = $this->garantias->pagadas($reservas);

        return [
            'pendientes' => $reservas->whereNull('danos_decision')->count(),
            'danos' => $reservas->map(function (Reserva $r) use ($areas, $unidades, $pagadas): array {
                $g = $this->garantias->de($r, $pagadas[$r->id]);

                return [
                    'reserva_id' => $r->id, 'area' => (string) ($areas[$r->amenidad_id] ?? 'Área común'), 'unidad' => (string) ($unidades[$r->unidad_id] ?? ''),
                    'fecha' => $r->fecha->toDateString(), 'danos' => $r->danos, 'garantia' => (string) $r->garantia,
                    'decision' => $r->danos_decision, 'garantia_estado' => $g['estado'] ?? null,
                    'por_devolver' => ($g['estado'] ?? null) === GarantiaDeReserva::POR_DEVOLVER ? Dinero::texto($g['monto']) : null,
                ];
            })->values()->all(),
        ];
    }
}
