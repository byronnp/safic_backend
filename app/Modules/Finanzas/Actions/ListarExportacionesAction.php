<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\Exportacion;

/**
 * Consulta: las últimas exportaciones del condominio con su código, quién las hizo y cuándo. Es la
 * bitácora de exportaciones (la del contador incluida).
 */
final class ListarExportacionesAction
{
    /** @return list<array<string, mixed>> */
    public function execute(int $limite = 20): array
    {
        return Exportacion::query()->orderByDesc('id')->limit($limite)->get()->map(fn (Exportacion $e): array => [
            'id' => $e->id,
            'codigo' => $e->codigo,
            'reporte' => $e->reporte,
            'titulo' => $e->titulo,
            'formato' => $e->formato,
            'filas' => $e->filas,
            'user_nombre' => $e->user_nombre,
            'user_rol' => $e->user_rol,
            'en' => $e->created_at->toIso8601String(),
        ])->all();
    }
}
