<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;

/**
 * Consulta para el residente: las áreas que puede reservar (activas, con reglas guardadas) y las unidades
 * desde las que puede hacerlo.
 */
final class ListarAreasReservablesAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidades, private readonly Calendario $calendario) {}

    /** @return array{unidades: list<array{unidad_id: int, codigo: string}>, areas: list<array<string, mixed>>} */
    public function execute(User $user): array
    {
        $hoy = $this->calendario->hoy();
        $reglas = ReglaArea::query()->get()->keyBy('amenidad_id');
        $areas = CondominioAmenidad::query()->where('reservable', true)->where('activa', true)->whereIn('id', $reglas->keys())->orderBy('nombre')->get();

        return [
            'unidades' => array_map(fn (array $u): array => ['unidad_id' => $u['unidad_id'], 'codigo' => $u['codigo']], $this->unidades->execute($user->id)),
            'areas' => $areas->map(function (CondominioAmenidad $a) use ($reglas, $hoy): array {
                /** @var ReglaArea $r */
                $r = $reglas->get($a->id);

                return [
                    'id' => $a->id,
                    'nombre' => $a->nombre,
                    'ubicacion' => $a->ubicacion,
                    'estado' => $a->mantenimiento_hasta !== null && $a->mantenimiento_hasta->toDateString() >= $hoy ? 'mantenimiento' : 'disponible',
                    'mantenimiento_hasta' => $a->mantenimiento_hasta !== null && $a->mantenimiento_hasta->toDateString() >= $hoy ? $a->mantenimiento_hasta->toDateString() : null,
                    'requiere_aprobacion' => (bool) $a->requiere_aprobacion,
                    'capacidad' => $r->capacidad,
                    'duracion_min_horas' => $r->duracion_min_horas,
                    'duracion_max_horas' => $r->duracion_max_horas,
                    'costo' => (string) $r->costo,
                    'garantia' => (string) $r->garantia,
                    'reglamento' => $r->reglamento,
                ];
            })->values()->all(),
        ];
    }
}
