<?php

namespace App\Modules\Asambleas\Actions;

use App\Modules\Asambleas\Models\Asamblea;

/** Consulta de la administración: las asambleas del condominio, las próximas primero. */
final class ListarAsambleasAction
{
    public function __construct(private readonly VerAsambleaAction $ver) {}

    /** @return list<array<string, mixed>> */
    public function execute(?string $estado): array
    {
        return Asamblea::query()->withCount('puntos')
            ->when($estado !== null, fn ($q) => $q->where('estado', $estado))
            ->orderByDesc('fecha_hora')->limit(100)->get()
            ->map(fn (Asamblea $a): array => $this->ver->resumen($a) + ['puntos' => $a->puntos_count])->values()->all();
    }
}
