<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Menu\Models\MenuItem;
use App\Core\Menu\Services\MenuService;
use Spatie\Permission\Models\Role;

/**
 * Consulta del super admin: el menú que vería alguien con solo ese perfil, y cuántas
 * pantallas activas no le llegan (por permiso o por no estar asignadas).
 */
final class VistaPreviaMenuAction
{
    public function __construct(private readonly MenuService $menus) {}

    /**
     * @return array{menu: list<array<string, mixed>>, ocultos: int}
     */
    public function execute(string $ambito, string $perfil): array
    {
        $rol = Role::query()->whereNull('condominio_id')->where('guard_name', 'api')->where('name', $perfil)->firstOrFail();
        $menu = $this->menus->paraPerfil($rol, $ambito);

        $totales = MenuItem::query()->where('ambito', $ambito)->where('activo', true)->whereNotNull('ruta')->count();

        return ['menu' => $menu, 'ocultos' => max(0, $totales - $this->hojas($menu))];
    }

    /** @param  list<array<string, mixed>>  $arbol */
    private function hojas(array $arbol): int
    {
        $total = 0;
        foreach ($arbol as $item) {
            $total += isset($item['hijos']) ? $this->hojas($item['hijos']) : 1;
        }

        return $total;
    }
}
