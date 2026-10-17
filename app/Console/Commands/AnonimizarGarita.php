<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Garita\Actions\AnonimizarGaritaAction;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Anonimiza los datos de los visitantes con más de 12 meses (LOPDP), en cada condominio. Corre cada día a las 03:00.
 */
class AnonimizarGarita extends Command
{
    protected $signature = 'garita:anonimizar';

    protected $description = 'Anonimiza los datos de visitantes con más de 12 meses';

    public function handle(TenantContext $tenant, AnonimizarGaritaAction $anonimizar): int
    {
        $accesos = $autorizaciones = $paquetes = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $r = $tenant->run((int) $id, fn (): array => $anonimizar->execute());
            $accesos += $r['accesos'];
            $autorizaciones += $r['autorizaciones'];
            $paquetes += $r['paquetes'];
        }
        $this->info("Accesos anonimizados: {$accesos} · Autorizaciones anonimizadas: {$autorizaciones} · Paquetes anonimizados: {$paquetes}");

        return self::SUCCESS;
    }
}
