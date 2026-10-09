<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Usuarios\Actions\ListarSolicitudesRolPendientesAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Consulta del super admin: la matriz de roles de condominio por permiso, con las reglas
 * fijas del código (qué permisos nunca recibe cada rol) y los pedidos de rol pendientes.
 * Los roles de plataforma no se editan aquí.
 */
final class ListarRolesAdminAction
{
    public function __construct(private readonly ListarSolicitudesRolPendientesAction $solicitudes) {}

    /**
     * @return array{roles: list<array<string, mixed>>, permisos: list<array<string, mixed>>, solicitudes: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $permisos = array_values(array_filter(Permiso::cases(), fn (Permiso $p) => ! $p->esDePlataforma()));

        $enUso = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->where('condominio_id', '>', 0)
            ->selectRaw('role_id, count(distinct condominio_id) as condominios')
            ->groupBy('role_id')
            ->pluck('condominios', 'role_id');

        $roles = self::rolesDeCondominio()
            ->map(fn (Role $rol) => $this->rol($rol, (int) ($enUso[$rol->id] ?? 0), $permisos))
            ->sortBy([['orden', 'asc'], ['nombre', 'asc']])
            ->map(fn (array $rol) => array_diff_key($rol, ['orden' => 1]))
            ->values()
            ->all();

        return [
            'roles' => $roles,
            'permisos' => array_map(fn (Permiso $p) => [
                'clave' => $p->value,
                'etiqueta' => $p->etiqueta(),
                'grupo' => $p->grupo(),
                'administrativo' => $p->esAdministrativo(),
                'escritura' => $p->esEscritura(),
            ], $permisos),
            'solicitudes' => $this->solicitudes->execute(),
        ];
    }

    /**
     * Roles globales que usan los condominios (no los de plataforma).
     *
     * @return Collection<int, Role>
     */
    public static function rolesDeCondominio(): Collection
    {
        return Role::query()
            ->whereNull('condominio_id')
            ->where('guard_name', 'api')
            ->with('permissions')
            ->get()
            ->reject(function (Role $rol): bool {
                $enum = Rol::tryFrom($rol->name);

                return $enum !== null
                    ? $enum->esDePlataforma()
                    : $rol->permissions->contains(fn ($p) => str_starts_with($p->name, 'plataforma.'));
            })
            ->values();
    }

    /**
     * @param  list<Permiso>  $permisos
     * @return array<string, mixed>
     */
    private function rol(Role $rol, int $condominios, array $permisos): array
    {
        $enum = Rol::tryFrom($rol->name);
        $concedidos = $rol->permissions->pluck('name')->filter(fn (string $n) => ! str_starts_with($n, 'plataforma.'))->sort()->values()->all();

        $bloqueos = [];
        $obligatorios = [];
        foreach ($permisos as $permiso) {
            $motivo = $enum?->motivoBloqueo($permiso);
            if ($motivo !== null) {
                $bloqueos[$permiso->value] = $motivo;
            }
            if ($enum?->exige($permiso) === true) {
                $obligatorios[] = $permiso->value;
            }
        }

        return [
            'clave' => $rol->name,
            'nombre' => $enum?->etiqueta() ?? Str::headline($rol->name),
            'tipo' => match (true) {
                $enum === null => 'adicional',
                $enum->esCargo() => 'cargo',
                default => 'sistema',
            },
            'condominios' => $condominios,
            'permisos' => $concedidos,
            'bloqueos' => (object) $bloqueos,
            'obligatorios' => $obligatorios,
            'orden' => $enum === null ? 100 : array_search($enum, [
                Rol::Administrador, Rol::Tesorero, Rol::Contador, Rol::Presidente, Rol::Vicepresidente,
                Rol::Secretario, Rol::Guardia, Rol::Mantenimiento, Rol::Residente,
            ], true),
        ];
    }
}
