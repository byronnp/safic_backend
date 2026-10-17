<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Actions\ActualizarRestriccionesAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Decreto 462 en cada condominio: notifica a quien llegó al límite de meses de deuda, pone en vigor
 * la restricción de áreas comunes cuando venció el aviso y la levanta cuando ya no corresponde.
 * Corre cada hora (routes/console.php).
 */
class ActualizarMorosidad extends Command
{
    protected $signature = 'finanzas:actualizar-morosidad';

    protected $description = 'Notifica, activa y levanta las restricciones de áreas comunes por mora';

    public function handle(TenantContext $tenant, ActualizarRestriccionesAction $actualizar): int
    {
        $n = $a = $l = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $r = $tenant->run((int) $id, fn (): array => $actualizar->execute());
            $n += $r['notificadas'];
            $a += $r['activadas'];
            $l += $r['levantadas'];
        }
        $this->info("Notificadas: {$n} · activadas: {$a} · levantadas: {$l}");

        return self::SUCCESS;
    }
}
