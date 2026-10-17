<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Actions\EnviarRecordatoriosAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Envía, en cada condominio, los recordatorios de cuota automáticos: 3 días antes del vencimiento,
 * el día y cada 15 días de atraso. Corre cada día a las 07:00 (routes/console.php).
 */
class RecordatoriosDeMora extends Command
{
    protected $signature = 'finanzas:recordatorios-de-mora';

    protected $description = 'Envía los recordatorios de cuota (por vencer, vence hoy, atrasada)';

    public function handle(TenantContext $tenant, EnviarRecordatoriosAction $recordatorios): int
    {
        $total = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $recordatorios->automaticos());
        }
        $this->info("Recordatorios enviados: {$total}");

        return self::SUCCESS;
    }
}
