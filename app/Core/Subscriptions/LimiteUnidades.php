<?php

namespace App\Core\Subscriptions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Total de unidades contratadas por el condominio (condominios.total_unidades).
 * Es un límite estricto: no se registran más unidades que las contratadas.
 * Parqueaderos y bodegas no cuentan.
 */
final class LimiteUnidades
{
    /** Tipos de unidad que cuentan para el total contratado. */
    public const TIPOS_CON_CUPO = ['departamento', 'casa', 'local'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function total(): int
    {
        return (int) DB::table('condominios')->where('id', $this->tenant->require())->value('total_unidades');
    }

    public function registradas(): int
    {
        return DB::table('unidades')
            ->where('condominio_id', $this->tenant->require())
            ->whereNull('deleted_at')
            ->whereIn('tipo', self::TIPOS_CON_CUPO)
            ->count();
    }

    public static function cuenta(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_CON_CUPO, true);
    }

    /**
     * Falla con 409 si no queda cupo para $nuevas unidades. Bloquea la fila del
     * condominio hasta el fin de la transacción para que dos altas simultáneas
     * no pasen el límite. Llamar dentro de la transacción que crea las unidades.
     */
    public function asegurarCupo(int $nuevas = 1): void
    {
        $total = (int) DB::table('condominios')
            ->where('id', $this->tenant->require())
            ->lockForUpdate()
            ->value('total_unidades');

        $registradas = $this->registradas();

        if ($registradas + $nuevas > $total) {
            throw new ApiException(
                'LIMITE_UNIDADES',
                'Alcanzaste el total de unidades contratadas; solicita un aumento.',
                409,
                ['total' => $total, 'registradas' => $registradas],
            );
        }
    }
}
