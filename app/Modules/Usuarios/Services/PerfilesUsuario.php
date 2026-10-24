<?php

namespace App\Modules\Usuarios\Services;

use App\Core\Access\Catalogo;
use App\Core\Access\RolInfo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Perfiles (roles de spatie) de un usuario en el condominio activo. Solo toca los
 * perfiles que el administrador asigna (roles `asignable` del catálogo); los cargos de directiva
 * y "residente" se manejan por su propio camino y aquí no se pierden.
 */
final class PerfilesUsuario
{
    public function __construct(private readonly TenantContext $tenant, private readonly Catalogo $catalogo) {}

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
    public function perfilAsignable(User $user): ?RolInfo
    {
        $nombres = $this->de([$user->id])[$user->id] ?? [];

        foreach ($this->catalogo->asignables() as $rol) {
            if (in_array($rol->clave, $nombres, true)) {
                return $rol;
            }
        }

        return null;
    }

    /** Suma un rol (ej. un cargo de directiva) sin tocar los demás. */
    public function agregar(User $user, RolInfo $rol): void
    {
        $this->enEquipo($user, function () use ($user, $rol): void {
            if (! $user->hasRole($rol->clave)) {
                $user->assignRole($rol->clave);
            }
        });
    }

    /** Quita un rol (ej. un cargo que termina) sin tocar los demás. */
    public function quitar(User $user, RolInfo $rol): void
    {
        $this->enEquipo($user, function () use ($user, $rol): void {
            if ($user->hasRole($rol->clave)) {
                $user->removeRole($rol->clave);
            }
        });
    }

    /** @param  callable(): void  $accion */
    private function enEquipo(User $user, callable $accion): void
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($this->tenant->require());

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $accion();
        } finally {
            setPermissionsTeamId($anterior);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** Reemplaza el perfil asignable del usuario por $rol (conserva cargos y residente). */
    public function asignar(User $user, RolInfo $rol): void
    {
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId($this->tenant->require());

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            foreach ($this->catalogo->asignables() as $asignable) {
                if ($asignable->clave !== $rol->clave && $user->hasRole($asignable->clave)) {
                    $user->removeRole($asignable->clave);
                }
            }
            if (! $user->hasRole($rol->clave)) {
                $user->assignRole($rol->clave);
            }
        } finally {
            setPermissionsTeamId($anterior);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
