<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Menu\Models\MenuItem;
use App\Core\Menu\Services\MenuService;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Consulta pública del módulo Usuarios para la pantalla Roles del condominio: los roles que
 * puede asignar, qué permite cada uno, cuántas personas lo tienen aquí y el menú que verían.
 * Los roles los define la plataforma; aquí solo se leen.
 */
final class ListarRolesAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MenuService $menus,
        private readonly LimiteUsuarios $limite,
    ) {}

    /**
     * @return array{roles: list<array<string, mixed>>, permisos: list<array<string, mixed>>, cupo: array{plan: string|null, limite: int|null, usados: int}}
     */
    public function execute(): array
    {
        $usuarios = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->where('condominio_id', $this->tenant->require())
            ->selectRaw('role_id, count(distinct model_id) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $roles = Role::query()
            ->whereNull('condominio_id')
            ->where('guard_name', 'api')
            ->with('permissions')
            ->get()
            ->reject(fn (Role $rol) => $this->esDePlataforma($rol))
            ->map(fn (Role $rol) => $this->rol($rol, (int) ($usuarios[$rol->id] ?? 0)))
            ->sortBy([['orden', 'asc'], ['nombre', 'asc']])
            ->map(fn (array $rol) => array_diff_key($rol, ['orden' => 1]))
            ->values()
            ->all();

        $plan = $this->limite->plan();

        return [
            'roles' => $roles,
            'permisos' => array_values(array_map(
                fn (Permiso $p) => ['clave' => $p->value, 'etiqueta' => $p->etiqueta(), 'grupo' => $p->grupo(), 'administrativo' => $p->esAdministrativo()],
                array_filter(Permiso::cases(), fn (Permiso $p) => ! $p->esDePlataforma()),
            )),
            'cupo' => ['plan' => $plan['nombre'] ?? null, 'limite' => $plan['limite'] ?? null, 'usados' => $this->limite->usados()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rol(Role $rol, int $usuarios): array
    {
        $enum = Rol::tryFrom($rol->name);
        $permisos = $rol->permissions->pluck('name')->filter(fn (string $n) => ! str_starts_with($n, 'plataforma.'))->sort()->values();
        $administrativo = $permisos->contains(fn (string $n) => Permiso::tryFrom($n)?->esAdministrativo() === true);

        return [
            'clave' => $rol->name,
            'nombre' => $enum?->etiqueta() ?? Str::headline($rol->name),
            'tipo' => match (true) {
                $enum === null => 'adicional',
                $enum->esCargo() => 'cargo',
                default => 'sistema',
            },
            'cuenta_cupo' => $enum?->cuentaParaCupo() ?? $administrativo,
            'usuarios' => $usuarios,
            'permisos' => $permisos->all(),
            'menu' => $this->menu($rol),
            'orden' => $enum === null ? 100 : array_search($enum, [
                Rol::Administrador, Rol::Contador, Rol::Guardia, Rol::Mantenimiento, Rol::Residente,
                Rol::Presidente, Rol::Vicepresidente, Rol::Secretario, Rol::Tesorero,
            ], true),
        ];
    }

    /**
     * Las pantallas (hojas) del menú de este perfil.
     *
     * @return list<array{etiqueta: string, icono: string}>
     */
    private function menu(Role $rol): array
    {
        $hojas = [];
        $recorrer = function (array $items) use (&$recorrer, &$hojas): void {
            foreach ($items as $item) {
                if (isset($item['hijos'])) {
                    $recorrer($item['hijos']);
                } elseif (isset($item['ruta'])) {
                    $hojas[] = ['etiqueta' => (string) $item['etiqueta'], 'icono' => (string) $item['icono']];
                }
            }
        };
        $recorrer($this->menus->paraPerfil($rol, MenuItem::AMBITO_CONDOMINIO));

        return $hojas;
    }

    private function esDePlataforma(Role $rol): bool
    {
        $enum = Rol::tryFrom($rol->name);

        return $enum !== null
            ? $enum->esDePlataforma()
            : $rol->permissions->contains(fn ($p) => str_starts_with($p->name, 'plataforma.'));
    }
}
