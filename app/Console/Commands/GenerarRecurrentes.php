<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Services\RecurrentesService;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea, en cada condominio, el gasto recurrente del mes a quienes ya les tocó su día y avisa que falta
 * la factura. Corre cada día a las 06:00 (routes/console.php).
 */
class GenerarRecurrentes extends Command
{
    protected $signature = 'finanzas:generar-recurrentes';

    protected $description = 'Crea los gastos recurrentes del mes y avisa que falta su factura';

    public function handle(TenantContext $tenant, RecurrentesService $recurrentes): int
    {
        $total = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $recurrentes->generar());
        }
        $this->info("Gastos recurrentes creados: {$total}");

        return self::SUCCESS;
    }
}
