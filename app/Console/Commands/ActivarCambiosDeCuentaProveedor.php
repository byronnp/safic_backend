<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Actions\CambiarCuentaProveedorAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Activa, en cada condominio, los cambios de cuenta bancaria de proveedores que ya cumplieron
 * sus 24 horas. Corre cada 15 minutos (routes/console.php).
 */
class ActivarCambiosDeCuentaProveedor extends Command
{
    protected $signature = 'finanzas:activar-cambios-de-cuenta';

    protected $description = 'Activa los cambios de cuenta de proveedores cumplidas las 24 horas';

    public function handle(TenantContext $tenant, CambiarCuentaProveedorAction $cambiar): int
    {
        $total = 0;
        foreach (DB::table('condominios')->pluck('id') as $id) {
            $total += $tenant->run((int) $id, fn (): int => $cambiar->activarVencidos());
        }
        $this->info("Cambios de cuenta activados: {$total}");

        return self::SUCCESS;
    }
}
