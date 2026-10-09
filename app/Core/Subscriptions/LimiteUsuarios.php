<?php

namespace App\Core\Subscriptions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Límite de usuarios administrativos del plan (planes.limite_administrativos).
 * Cuentan los usuarios con membresía activa y vigente que tienen un perfil que
 * consume cupo (administrador, contador o tesorero); una invitación pendiente
 * también reserva su lugar. Es un límite estricto.
 */
final class LimiteUsuarios
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array{nombre: string, limite: int}|null null si el condominio no tiene plan (sin límite)
     */
    public function plan(): ?array
    {
        $plan = DB::table('condominios')
            ->join('planes', 'planes.id', '=', 'condominios.plan_id')
            ->where('condominios.id', $this->tenant->require())
            ->first(['planes.nombre', 'planes.limite_administrativos']);

        return $plan === null ? null : ['nombre' => (string) $plan->nombre, 'limite' => (int) $plan->limite_administrativos];
    }

    public function usados(): int
    {
        return $this->consultaUsados()->distinct()->count('cu.user_id');
    }

    /** Membresías activas y vigentes con un perfil que consume cupo. */
    private function consultaUsados(): Builder
    {
        return DB::table('condominio_user as cu')
            ->join('model_has_roles as mr', function ($join): void {
                $join->on('mr.model_id', '=', 'cu.user_id')
                    ->on('mr.condominio_id', '=', 'cu.condominio_id')
                    ->where('mr.model_type', (new User)->getMorphClass());
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('cu.condominio_id', $this->tenant->require())
            ->where('cu.activo', true)
            ->where(fn ($q) => $q->whereNull('cu.acceso_hasta')->orWhere('cu.acceso_hasta', '>=', $this->calendario->hoy()))
            ->whereIn('r.name', Rol::nombresQueCuentanParaCupo());
    }

    /** ¿Esta persona ya ocupa un lugar del cupo en el condominio activo? */
    public function consume(int $userId): bool
    {
        return $this->consultaUsados()->where('cu.user_id', $userId)->exists();
    }

    /**
     * Falla con 409 si no queda cupo para $nuevos usuarios. Bloquea la fila del
     * condominio hasta el fin de la transacción para que dos altas simultáneas no
     * pasen el límite. Llamar dentro de la transacción que cambia los usuarios.
     */
    public function asegurarCupo(int $nuevos = 1): void
    {
        DB::table('condominios')->where('id', $this->tenant->require())->lockForUpdate()->value('id');

        $plan = $this->plan();
        if ($plan === null) {
            return;
        }

        $usados = $this->usados();
        if ($usados + $nuevos > $plan['limite']) {
            throw new ApiException(
                'LIMITE_USUARIOS',
                "Tu plan {$plan['nombre']} permite {$plan['limite']} usuarios administrativos. Desactiva a uno o sube de plan.",
                409,
                ['limite' => $plan['limite'], 'usados' => $usados],
            );
        }
    }
}
