<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\BloqueoArea;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

/**
 * Consulta de la administración: la agenda de un rango de días (una semana en pantalla) con las áreas reservables,
 * sus reservas (pendientes y confirmadas) y sus bloqueos.
 */
final class ConsultarAgendaAction
{
    public const MAX_DIAS = 14;

    public function __construct(private readonly Calendario $calendario) {}

    /** @return array{desde: string, hasta: string, areas: list<array<string, mixed>>, reservas: list<array<string, mixed>>, bloqueos: list<array<string, mixed>>} */
    public function execute(?string $desde, int $dias): array
    {
        $dias = max(1, min($dias, self::MAX_DIAS));
        $primero = CarbonImmutable::parse($desde ?? $this->calendario->hoy());
        $hasta = $primero->addDays($dias - 1)->toDateString();
        $hoy = $this->calendario->hoy();

        $areas = CondominioAmenidad::query()->where('reservable', true)->where('activa', true)->orderBy('nombre')->get();
        $reglas = ReglaArea::query()->whereIn('amenidad_id', $areas->pluck('id'))->get()->keyBy('amenidad_id');

        $reservas = Reserva::query()->whereIn('estado', Reserva::VIVAS)->whereBetween('fecha', [$primero->toDateString(), $hasta])
            ->orderBy('fecha')->orderBy('inicio')->get();
        $unidades = Unidad::query()->whereIn('id', $reservas->pluck('unidad_id'))->get()->keyBy('id');
        $bloqueos = BloqueoArea::query()->whereBetween('fecha', [$primero->toDateString(), $hasta])->orderBy('fecha')->orderBy('inicio')->get();

        return [
            'desde' => $primero->toDateString(),
            'hasta' => $hasta,
            'areas' => $areas->map(fn (CondominioAmenidad $a): array => [
                'id' => $a->id, 'nombre' => $a->nombre,
                'capacidad' => $reglas->get($a->id)?->capacidad,
                'costo' => (string) ($reglas->get($a->id)->costo ?? '0.00'),
                'garantia' => (string) ($reglas->get($a->id)->garantia ?? '0.00'),
                'duracion_max_horas' => $reglas->get($a->id)->duracion_max_horas ?? null,
                'configurada' => $reglas->has($a->id),
                'en_mantenimiento' => $a->mantenimiento_hasta !== null && $a->mantenimiento_hasta->toDateString() >= $hoy,
            ])->values()->all(),
            'reservas' => $reservas->map(fn (Reserva $r): array => [
                'id' => $r->id, 'amenidad_id' => $r->amenidad_id, 'unidad' => $unidades->get($r->unidad_id)->codigo ?? '',
                'fecha' => $r->fecha->toDateString(), 'inicio' => substr($r->inicio, 0, 5), 'fin' => substr($r->fin, 0, 5), 'estado' => $r->estado,
            ])->values()->all(),
            'bloqueos' => $bloqueos->map(fn (BloqueoArea $b): array => [
                'id' => $b->id, 'amenidad_id' => $b->amenidad_id, 'fecha' => $b->fecha->toDateString(),
                'inicio' => $b->inicio === null ? null : substr($b->inicio, 0, 5), 'fin' => $b->fin === null ? null : substr($b->fin, 0, 5), 'motivo' => $b->motivo,
            ])->values()->all(),
        ];
    }
}
