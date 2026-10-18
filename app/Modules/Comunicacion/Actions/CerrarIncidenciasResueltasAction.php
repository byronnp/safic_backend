<?php

namespace App\Modules\Comunicacion\Actions;

use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Comunicacion\Services\TransicionesDeIncidencia;
use Illuminate\Support\Facades\DB;

/**
 * Cierra solas las incidencias resueltas que el residente no respondió en 7 días.
 */
final class CerrarIncidenciasResueltasAction
{
    public function __construct(private readonly TransicionesDeIncidencia $transiciones) {}

    public function execute(): int
    {
        $cerradas = 0;
        $ids = Incidencia::query()->where('estado', Incidencia::RESUELTO)->where('resuelta_en', '<=', now()->subDays(Incidencia::DIAS_PARA_CERRAR))->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$cerradas): void {
                $i = Incidencia::query()->lockForUpdate()->find($id);
                // Pudo responder el residente justo antes
                if ($i !== null && $i->estado === Incidencia::RESUELTO) {
                    $this->transiciones->cambiar($i, Incidencia::CERRADO, 'automatico');
                    $cerradas++;
                }
            });
        }

        return $cerradas;
    }
}
