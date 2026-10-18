<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta de la administración: el tablero de incidencias (abiertas, en proceso, resueltas y las cerradas en los últimos 30 días) y el
 * tiempo promedio de primera respuesta de los últimos 30 días.
 */
final class TableroIncidenciasAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** @return array{tiempo_promedio_horas: float|null, incidencias: list<array<string, mixed>>} */
    public function execute(): array
    {
        $zona = $this->calendario->zonaHoraria();
        $desde = now()->subDays(30);
        $filas = Incidencia::query()->where(fn ($q) => $q->where('estado', '!=', Incidencia::CERRADO)->orWhere('cerrada_en', '>=', $desde))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(300)->get();
        $unidades = Unidad::query()->withTrashed()->whereIn('id', $filas->pluck('unidad_id')->filter())->pluck('codigo', 'id');

        $respondidas = Incidencia::query()->where('created_at', '>=', $desde)->whereNotNull('primera_respuesta_en')->get(['created_at', 'primera_respuesta_en']);
        $promedio = $respondidas->isEmpty() ? null : round($respondidas->avg(fn (Incidencia $i): float => $i->created_at->diffInMinutes($i->primera_respuesta_en) / 60), 1);

        return [
            'tiempo_promedio_horas' => $promedio,
            'incidencias' => $filas->map(fn (Incidencia $i): array => [
                'id' => $i->id, 'numero' => $i->numero, 'titulo' => $i->titulo, 'categoria' => $i->categoria, 'prioridad' => $i->prioridad, 'estado' => $i->estado,
                'unidad' => $unidades[$i->unidad_id] ?? null, 'creada_en' => $i->created_at->setTimezone($zona)->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
