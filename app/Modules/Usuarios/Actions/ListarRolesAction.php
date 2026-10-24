<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Access\Catalogo;
use App\Core\Menu\Models\MenuItem;
use App\Core\Menu\Services\MenuService;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        private readonly Catalogo $catalogo,
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
            ->filter(fn (Role $rol) => $rol->ambito === Catalogo::CONDOMINIO)
            ->map(fn (Role $rol) => $this->rol($rol, (int) ($usuarios[$rol->id] ?? 0)))
            ->sortBy([['orden', 'asc'], ['nombre', 'asc']])
            ->map(fn (array $rol) => array_diff_key($rol, ['orden' => 1]))
            ->values()
            ->all();

        $plan = $this->limite->plan();

        return [
            'roles' => $roles,
            'permisos' => array_values(array_map(
                fn ($p) => ['clave' => $p->clave, 'etiqueta' => $p->etiqueta, 'grupo' => $p->grupo, 'administrativo' => $p->administrativo],
                $this->catalogo->permisos(Catalogo::CONDOMINIO),
            )),
            'cupo' => ['plan' => $plan['nombre'] ?? null, 'limite' => $plan['limite'] ?? null, 'usados' => $this->limite->usados()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rol(Role $rol, int $usuarios): array
    {
        $info = $this->catalogo->rol($rol->name);
        $permisos = $rol->permissions->pluck('name')->filter(fn (string $n) => ! str_starts_with($n, 'plataforma.'))->sort()->values();
        // Un rol que creó el super admin consume cupo si tiene algún permiso administrativo
        $administrativo = $permisos->contains(fn (string $n) => $this->catalogo->permiso($n)?->administrativo === true);

        return [
            'clave' => $rol->name,
            'nombre' => $info->etiqueta ?? $this->catalogo->etiquetaDeRol($rol->name),
            'tipo' => match (true) {
                $info?->esCargo === true => 'cargo',
                $info?->sistema === true => 'sistema',
                default => 'adicional',
            },
            'cuenta_cupo' => $info->cuentaCupo ?? $administrativo,
            'usuarios' => $usuarios,
            'permisos' => $permisos->all(),
            'menu' => $this->menu($rol),
            'orden' => $info->orden ?? 100,
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
}
