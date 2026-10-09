<?php

namespace App\Modules\Usuarios\Services;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Perfiles (roles de spatie) de un usuario en el condominio activo. Solo toca los
 * perfiles que el administrador asigna (Rol::asignables()); los cargos de directiva
 * y "residente" se manejan por su propio camino y aquí no se pierden.
 */
final class PerfilesUsuario
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>> nombres de rol por usuario
     */
    public function de(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $filas = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('mr.condominio_id', $this->tenant->require())
            ->whereIn('mr.model_id', $userIds)
            ->orderBy('r.name')
            ->get(['mr.model_id', 'r.name']);

        $porUsuario = [];
        foreach ($filas as $fila) {
            $porUsuario[(int) $fila->model_id][] = (string) $fila->name;
        }

        return $porUsuario;
    }

    /** El perfil asignable que tiene (el primero), o null si solo tiene cargos o es residente. */
    public function perfilAsignable(User $user): ?Rol
    {
        $nombres = $this->de([$user->id])[$user->id] ?? [];

        foreach (Rol::asignables() as $rol) {
            if (in_array($rol->value, $nombres, true)) {
                return $rol;
            }
        }

        return null;
    }

    /** Reemplaza el perfil asignable del usuario por $rol (conserva cargos y residente). */
    public function asignar(User $user, Rol $rol): void
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($this->tenant->require());

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            foreach (Rol::asignables() as $asignable) {
                if ($asignable !== $rol && $user->hasRole($asignable->value)) {
                    $user->removeRole($asignable->value);
                }
            }
            if (! $user->hasRole($rol->value)) {
                $user->assignRole($rol->value);
            }
        } finally {
            setPermissionsTeamId($anterior);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
