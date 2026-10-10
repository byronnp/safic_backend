<?php

namespace App\Modules\Unidades\Actions;

use App\Modules\Unidades\Models\Unidad;

/**
 * Consulta pública del módulo Unidades para Finanzas: las unidades que tienen cuota mensual
 * (las que se emiten cada mes) y cuántas no la tienen. Parqueaderos y bodegas sin cuota
 * simplemente no se cobran.
 */
final class UnidadesACobrarAction
{
    /**
     * @return array{unidades: list<array{id: int, codigo: string, cuota_mensual: string}>, sin_cuota: int}
     */
    public function execute(): array
    {
        $unidades = Unidad::query()
            ->where('cuota_mensual', '>', 0)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'cuota_mensual'])
            ->map(fn (Unidad $u) => ['id' => $u->id, 'codigo' => $u->codigo, 'cuota_mensual' => (string) $u->cuota_mensual])
            ->all();

        return [
            'unidades' => $unidades,
            'sin_cuota' => Unidad::query()->where(fn ($q) => $q->whereNull('cuota_mensual')->orWhere('cuota_mensual', '<=', 0))->count(),
        ];
    }
}
