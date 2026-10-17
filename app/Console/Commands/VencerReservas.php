<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Services\VencimientosDeReservas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vence las solicitudes de reserva sin respuesta y cancela las reservas sin pagar a tiempo, en cada condominio.
 * Corre cada 15 minutos (routes/console.php).
 */
class VencerReservas extends Command
{
    protected $signature = 'reservas:vencer';

    protected $description = 'Vence solicitudes de reserva sin respuesta y cancela reservas sin pagar';

    public function handle(TenantContext $tenant, VencimientosDeReservas $vencimientos): int
    {
        $vencidas = $canceladas = 0;
        foreach (DB::table('condominios')->where('estado', '!=', Condominio::ESTADO_SUSPENDIDO)->pluck('id') as $id) {
            $r = $tenant->run((int) $id, fn (): array => $vencimientos->procesar());
            $vencidas += $r['vencidas'];
            $canceladas += $r['canceladas'];
        }
        $this->info("Solicitudes vencidas: {$vencidas} · Reservas canceladas por falta de pago: {$canceladas}");

        return self::SUCCESS;
    }
}
