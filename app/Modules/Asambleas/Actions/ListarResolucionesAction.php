<?php

namespace App\Modules\Asambleas\Actions;

use App\Models\User;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Resolucion;

/** El historial de resoluciones, las más recientes primero. Un propietario ve solo las de actas ya firmadas y publicadas. */
final class ListarResolucionesAction
{
    public function __construct(private readonly VerActaAction $actas) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $user): array
    {
        $actas = Acta::query()->get()->keyBy('id');
        $soloPublicadas = $this->actas->soloPropietario($user);

        return Resolucion::query()->orderByDesc('vigente_desde')->orderByDesc('id')->limit(300)->get()
            ->filter(fn (Resolucion $r): bool => ! $soloPublicadas || $actas[$r->acta_id]->estaFirmada())
            ->map(fn (Resolucion $r): array => [
                'id' => $r->id, 'asamblea_id' => $r->asamblea_id, 'asamblea' => (string) ($actas[$r->acta_id]->contenido['asamblea']['titulo'] ?? ''),
                'acta_numero' => $actas[$r->acta_id]->numero, 'texto' => $r->texto, 'vigente_desde' => $r->vigente_desde->toDateString(),
                'publicada' => $actas[$r->acta_id]->estaFirmada(),
            ])->values()->all();
    }
}
