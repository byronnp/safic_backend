<?php

namespace App\Modules\Reservas\Services;

use App\Modules\Reservas\Models\BloqueoArea;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qué se cruza con un bloqueo. Un bloqueo sin horas ocupa todo el día. Las horas son «HH:MM».
 */
final class BloqueosDeArea
{
    /** Si el tramo de ese día cae sobre algún bloqueo del área. */
    public function bloquea(int $amenidadId, string $fecha, string $inicio, string $fin): bool
    {
        return BloqueoArea::query()->where('amenidad_id', $amenidadId)->whereDate('fecha', $fecha)->get()
            ->contains(fn (BloqueoArea $b): bool => $this->cruza($b, $inicio, $fin));
    }

    /** @return Collection<int, Reserva> Las reservas vivas del área que caen sobre ese bloqueo. */
    public function reservasAfectadas(BloqueoArea $b): Collection
    {
        return Reserva::query()->where('amenidad_id', $b->amenidad_id)->whereDate('fecha', $b->fecha->toDateString())->whereIn('estado', Reserva::VIVAS)->get()
            ->filter(fn (Reserva $r): bool => $this->cruza($b, substr($r->inicio, 0, 5), substr($r->fin, 0, 5)))->values();
    }

    private function cruza(BloqueoArea $b, string $inicio, string $fin): bool
    {
        if ($b->inicio === null || $b->fin === null) {
            return true;
        }

        return substr($b->inicio, 0, 5) < $fin && substr($b->fin, 0, 5) > $inicio;
    }
}
