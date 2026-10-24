<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Asambleas\Actions\RecordarAsambleasAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recordatorios de las asambleas convocadas: 3 días y 2 horas antes. Corre cada 15 minutos.
 */
class RecordarAsambleas extends Command
{
    protected $signature = 'asambleas:recordar';

    protected $description = 'Envía los recordatorios de asambleas convocadas (3 días y 2 horas antes)';

    public function handle(TenantContext $tenant, RecordarAsambleasAction $recordar): int
    {
        $total = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $recordar->execute());
        }
        $this->info("Recordatorios de asambleas enviados: {$total}");

        return self::SUCCESS;
    }
}
