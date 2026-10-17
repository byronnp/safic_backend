<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Garita\Actions\RecordarPaquetesAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Avisa a las unidades con un paquete que lleva 7 días o más en la garita. Corre cada día a las 09:00.
 */
class RecordarPaquetes extends Command
{
    protected $signature = 'garita:recordar-paquetes';

    protected $description = 'Recuerda los paquetes con más de 7 días en la garita';

    public function handle(TenantContext $tenant, RecordarPaquetesAction $recordar): int
    {
        $total = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $recordar->execute());
        }
        $this->info("Recordatorios de paquetes enviados: {$total}");

        return self::SUCCESS;
    }
}
