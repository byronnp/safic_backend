<?php

namespace App\Modules\Garita\Actions;

use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Carbon\CarbonImmutable;

/**
 * Consulta para el residente: quién espera en la garita por sus unidades y cuánto tiempo queda para responder.
 */
final class ListarMisSolicitudesIngresoAction
{
    public function __construct(private readonly Calendario $calendario, private readonly UnidadesDeCuentaAction $unidades) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $mias = collect($this->unidades->execute($user->id))->keyBy('unidad_id');
        $ahora = CarbonImmutable::now();

        return SolicitudIngreso::query()->whereIn('unidad_id', $mias->keys())->where('estado', SolicitudIngreso::PENDIENTE)->where('expira_en', '>', $ahora)
            ->orderBy('created_at')->get()
            ->map(fn (SolicitudIngreso $s): array => [
                'id' => $s->id, 'nombre' => $s->visitante_nombre, 'tipo' => $s->tipo, 'placa' => $s->placa, 'unidad' => $mias->get($s->unidad_id)['codigo'] ?? '',
                'creada_en' => $s->created_at->setTimezone($this->calendario->zonaHoraria())->toIso8601String(),
                'segundos_restantes' => max(0, (int) $ahora->diffInSeconds($s->expira_en, false)),
            ])->values()->all();
    }
}
