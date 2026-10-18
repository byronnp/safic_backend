<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Comunicacion\Actions\CerrarIncidenciasResueltasAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cierra las incidencias resueltas que el residente no respondió en 7 días, en cada condominio. Corre cada día a las 04:00.
 */
class CerrarIncidencias extends Command
{
    protected $signature = 'comunicacion:cerrar-incidencias';

    protected $description = 'Cierra las incidencias resueltas sin respuesta del residente en 7 días';

    public function handle(TenantContext $tenant, CerrarIncidenciasResueltasAction $cerrar): int
    {
        $total = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $cerrar->execute());
        }
        $this->info("Incidencias cerradas: {$total}");

        return self::SUCCESS;
    }
}
