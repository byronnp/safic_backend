<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Access\Catalogo;
use App\Core\Access\PermisoInfo;
use App\Models\User;
use App\Modules\Usuarios\Actions\ListarSolicitudesRolPendientesAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Consulta del super admin: la matriz de roles de condominio por permiso, con las reglas
 * fijas del código (qué permisos nunca recibe cada rol) y los pedidos de rol pendientes.
 * Los roles de plataforma no se editan aquí.
 */
final class ListarRolesAdminAction
{
    public function __construct(private readonly ListarSolicitudesRolPendientesAction $solicitudes, private readonly Catalogo $catalogo) {}

    /**
     * @return array{roles: list<array<string, mixed>>, permisos: list<array<string, mixed>>, solicitudes: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $permisos = array_values($this->catalogo->permisos(Catalogo::CONDOMINIO));

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
            'permisos' => array_map(fn (PermisoInfo $p) => [
                'clave' => $p->clave,
                'etiqueta' => $p->etiqueta,
                'grupo' => $p->grupo,
                'administrativo' => $p->administrativo,
                'escritura' => $p->escritura,
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
            ->where('ambito', Catalogo::CONDOMINIO)
            ->with('permissions')
            ->get();
    }

    /**
     * @param  list<PermisoInfo>  $permisos
     * @return array<string, mixed>
     */
    private function rol(Role $rol, int $condominios, array $permisos): array
    {
        $info = $this->catalogo->rol($rol->name);
        $concedidos = $rol->permissions->pluck('name')->filter(fn (string $n) => ! str_starts_with($n, 'plataforma.'))->sort()->values()->all();

        $bloqueos = [];
        foreach ($permisos as $permiso) {
            $motivo = $info?->motivoBloqueo($permiso);
            if ($motivo !== null) {
                $bloqueos[$permiso->clave] = $motivo;
            }
        }

        return [
            'clave' => $rol->name,
            'nombre' => $info->etiqueta ?? $this->catalogo->etiquetaDeRol($rol->name),
            'tipo' => match (true) {
                $info?->esCargo === true => 'cargo',
                $info?->sistema === true => 'sistema',
                default => 'adicional',
            },
            'condominios' => $condominios,
            'permisos' => $concedidos,
            'bloqueos' => (object) $bloqueos,
            'obligatorios' => $info->obligatorios ?? [],
            'orden' => $info->orden ?? 100,
        ];
    }
}
