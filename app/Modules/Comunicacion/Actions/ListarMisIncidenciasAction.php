<?php

namespace App\Modules\Comunicacion\Actions;

use App\Models\User;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;

/**
 * Consulta para el residente: las incidencias que reportó él o alguien de sus unidades, con sus fotos y los comentarios visibles
 * (sin notas internas). Las abiertas primero.
 */
final class ListarMisIncidenciasAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidades, private readonly DetalleIncidenciaAction $detalle) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');

        return Incidencia::query()->where(fn ($q) => $q->where('reportado_por', $user->id)->orWhereIn('unidad_id', $ids))
            ->orderByRaw("case estado when 'cerrado' then 1 else 0 end")->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (Incidencia $i): array => $this->detalle->execute($i, false))->values()->all();
    }
}
