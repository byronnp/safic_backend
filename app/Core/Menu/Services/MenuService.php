<?php

namespace App\Core\Menu\Services;

use App\Core\Menu\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma el menú de un usuario en el equipo activo de spatie (condominio del
 * header o plataforma). Una hoja se muestra solo si:
 *  1. está asignada a alguno de los perfiles (roles) del usuario en ese equipo, y
 *  2. el usuario tiene el permiso de la hoja (o la hoja no pide permiso).
 * Los módulos y secciones aparecen solo si les queda alguna hoja visible.
 * Ocultar un ítem no es seguridad: cada ruta de la API exige su permiso.
 */
final class MenuService
{
    /**
     * @return list<array<string, mixed>> Mismo formato que ItemMenu del frontend.
     */
    public function paraUsuario(User $user, string $ambito): array
    {
        // Las relaciones se cargan con el equipo ya fijado por el middleware.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        /** @var list<int> $rolIds */
        $rolIds = $user->roles()->pluck('roles.id')->all();

        if ($rolIds === []) {
            return [];
        }

        $permisos = $user->getAllPermissions()->pluck('name')->flip();
        $asignados = DB::table('menu_item_rol')->whereIn('role_id', $rolIds)->pluck('menu_item_id')->flip();

        $items = MenuItem::query()
            ->where('ambito', $ambito)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        $visible = fn (MenuItem $item): bool => $item->ruta !== null
            && $asignados->has($item->id)
            && ($item->permiso === null || $permisos->has($item->permiso));

        return $this->ramas($items->groupBy(fn (MenuItem $item) => $item->padre_id ?? 0), 0, $visible);
    }

    /**
     * @param  Collection<int|string, Collection<int, MenuItem>>  $porPadre
     * @param  callable(MenuItem): bool  $visible
     * @return list<array<string, mixed>>
     */
    private function ramas(Collection $porPadre, int $padreId, callable $visible): array
    {
        $resultado = [];

        foreach ($porPadre->get($padreId, collect()) as $item) {
            $hijos = $this->ramas($porPadre, $item->id, $visible);
            $esGrupo = $porPadre->has($item->id);

            if ($esGrupo ? $hijos === [] : ! $visible($item)) {
                continue;
            }

            $resultado[] = array_filter([
                'id' => $item->clave,
                'etiqueta' => $item->etiqueta,
                'icono' => $item->icono,
                'ruta' => $esGrupo ? null : $item->ruta,
                'permiso' => $esGrupo ? null : $item->permiso,
                'seccion' => $item->seccion ?: null,
                'hijos' => $esGrupo ? $hijos : null,
            ], fn ($valor) => $valor !== null);
        }

        return $resultado;
    }
}
