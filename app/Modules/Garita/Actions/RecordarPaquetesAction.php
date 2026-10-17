<?php

namespace App\Modules\Garita\Actions;

use App\Modules\Garita\Models\Paquete;
use App\Modules\Unidades\Models\Unidad;

/**
 * Recordatorio a los ocupantes de una unidad con un paquete que lleva 7 días o más en la garita. Se repite cada 7 días mientras siga ahí.
 */
final class RecordarPaquetesAction
{
    public function __construct(private readonly RecibirPaqueteAction $recibir) {}

    public function execute(): int
    {
        $enviados = 0;
        $codigos = Unidad::query()->withTrashed()->get(['id', 'codigo'])->pluck('codigo', 'id');

        $atrasados = Paquete::query()->where('estado', Paquete::EN_GARITA)->where('recibido_en', '<=', now()->subDays(Paquete::DIAS_ATRASADO))
            ->where(fn ($q) => $q->whereNull('recordatorio_en')->orWhere('recordatorio_en', '<=', now()->subDays(Paquete::DIAS_ATRASADO)))->get();

        foreach ($atrasados as $p) {
            $this->recibir->avisar($p, $codigos[$p->unidad_id] ?? '', true);
            $p->forceFill(['recordatorio_en' => now()])->save();
            $enviados++;
        }

        return $enviados;
    }
}
