<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\ReglaArea;

/**
 * Consulta: las áreas comunes del condominio (amenidades reservables) con sus reglas. Un área sin
 * reglas guardadas aún devuelve las reglas por defecto y `configurada: false`.
 */
final class ListarAreasAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        $areas = CondominioAmenidad::query()->where('reservable', true)->orderBy('nombre')->get();
        $reglas = ReglaArea::query()->whereIn('amenidad_id', $areas->pluck('id'))->get()->keyBy('amenidad_id');
        $hoy = $this->calendario->hoy();

        return $areas->map(fn (CondominioAmenidad $a): array => self::item($a, $reglas->get($a->id), $hoy))->values()->all();
    }

    /** @return array<string, mixed>|null */
    public function uno(int $id): ?array
    {
        $area = CondominioAmenidad::query()->where('reservable', true)->find($id);

        return $area === null ? null : self::item($area, ReglaArea::query()->where('amenidad_id', $id)->first(), $this->calendario->hoy());
    }

    /** @return array<string, mixed> */
    public static function item(CondominioAmenidad $a, ?ReglaArea $r, string $hoy): array
    {
        $estado = ! $a->activa ? 'inactiva' : ($a->mantenimiento_hasta !== null && $a->mantenimiento_hasta->toDateString() >= $hoy ? 'mantenimiento' : 'disponible');

        return [
            'id' => $a->id,
            'nombre' => $a->nombre,
            'ubicacion' => $a->ubicacion,
            'estado' => $estado,
            'requiere_aprobacion' => (bool) $a->requiere_aprobacion,
            'restringible_por_mora' => ! $a->esencial,
            'configurada' => $r !== null,
            'reglas' => [
                'abre' => substr($r->abre ?? '08:00', 0, 5),
                'cierra' => substr($r->cierra ?? '22:00', 0, 5),
                'duracion_min_horas' => $r->duracion_min_horas ?? 1,
                'duracion_max_horas' => $r->duracion_max_horas ?? 4,
                'anticipacion_min_horas' => $r->anticipacion_min_horas ?? 0,
                'anticipacion_max_dias' => $r->anticipacion_max_dias ?? 30,
                'capacidad' => $r?->capacidad,
                'reservas_por_unidad_mes' => $r?->reservas_por_unidad_mes,
                'dias_semana' => $r->dias_semana ?? [1, 2, 3, 4, 5, 6, 7],
                'costo' => (string) ($r->costo ?? '0.00'),
                'garantia' => (string) ($r->garantia ?? '0.00'),
                'reglamento' => $r?->reglamento,
            ],
        ];
    }
}
