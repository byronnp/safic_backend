<?php

namespace App\Modules\Reservas\Actions;

use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta de la administración: las solicitudes de reserva pendientes de aprobación, la más antigua primero
 * (la que vence antes), con quién la pidió y hasta cuándo se puede responder.
 */
final class ListarSolicitudesAction
{
    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        $reservas = Reserva::query()->where('estado', Reserva::PENDIENTE)->orderBy('created_at')->orderBy('id')->get();
        $areas = CondominioAmenidad::query()->whereIn('id', $reservas->pluck('amenidad_id'))->get()->keyBy('id');
        $unidades = Unidad::query()->whereIn('id', $reservas->pluck('unidad_id'))->get()->keyBy('id');
        $usuarios = User::query()->whereIn('id', $reservas->pluck('solicitante_id')->filter())->get()->keyBy('id');

        return $reservas->map(fn (Reserva $r): array => [
            'id' => $r->id,
            'area' => $areas->get($r->amenidad_id)->nombre ?? 'Área',
            'unidad' => $unidades->get($r->unidad_id)->codigo ?? '',
            'solicitante' => $usuarios->get($r->solicitante_id)->name ?? null,
            'fecha' => $r->fecha->toDateString(), 'inicio' => substr($r->inicio, 0, 5), 'fin' => substr($r->fin, 0, 5),
            'costo' => (string) $r->costo, 'garantia' => (string) $r->garantia,
            'solicitada_en' => $r->created_at->toIso8601String(),
            'vence_en' => $r->created_at->addHours(Reserva::HORAS_PARA_RESPONDER)->toIso8601String(),
        ])->values()->all();
    }
}
