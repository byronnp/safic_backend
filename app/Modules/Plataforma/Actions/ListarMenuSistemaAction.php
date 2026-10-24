<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Access\Catalogo;
use App\Core\Access\PermisoInfo;
use App\Core\Access\RolInfo;
use App\Core\Menu\Models\MenuItem;
use Illuminate\Support\Collection;

/**
 * Consulta del super admin: el catálogo de ítems del menú de un ámbito (condominio o
 * plataforma) con los perfiles que los ven, más lo que el editor necesita para elegir
 * (perfiles y permisos del ámbito).
 */
final class ListarMenuSistemaAction
{
    /**
     * @return array{items: list<array<string, mixed>>, roles: list<array{clave: string, nombre: string}>, permisos: list<array{clave: string, etiqueta: string, grupo: string}>}
     */
    public function execute(string $ambito): array
    {
        $items = MenuItem::query()
            ->where('ambito', $ambito)
            ->with('roles:id,name')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Collection<int, MenuItem>> $porPadre */
        $porPadre = $items->groupBy(fn (MenuItem $i) => $i->padre_id ?? 0);

        $ordenado = [];
        foreach ($porPadre->get(0, collect()) as $raiz) {
            $ordenado[] = $this->item($raiz);
            foreach ($porPadre->get($raiz->id, collect()) as $hijo) {
                $ordenado[] = $this->item($hijo);
            }
        }

        return [
            'items' => $ordenado,
            'roles' => self::rolesDelAmbito($ambito),
            'permisos' => self::permisosDelAmbito($ambito),
        ];
    }

    /**
     * @return list<array{clave: string, nombre: string}>
     */
    public static function rolesDelAmbito(string $ambito): array
    {
        $roles = app(Catalogo::class)->roles($ambito === MenuItem::AMBITO_PLATAFORMA ? Catalogo::PLATAFORMA : Catalogo::CONDOMINIO);

        return array_values(array_map(fn (RolInfo $r) => ['clave' => $r->clave, 'nombre' => $r->etiqueta], $roles));
    }

    /**
     * @return list<array{clave: string, etiqueta: string, grupo: string}>
     */
    public static function permisosDelAmbito(string $ambito): array
    {
        $permisos = app(Catalogo::class)->permisos($ambito === MenuItem::AMBITO_PLATAFORMA ? Catalogo::PLATAFORMA : Catalogo::CONDOMINIO);

        return array_values(array_map(fn (PermisoInfo $p) => ['clave' => $p->clave, 'etiqueta' => $p->etiqueta, 'grupo' => $p->grupo], $permisos));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(MenuItem $item): array
    {
        return [
            'id' => $item->id,
            'clave' => $item->clave,
            'padre_id' => $item->padre_id,
            'etiqueta' => $item->etiqueta,
            'icono' => $item->icono,
            'ruta' => $item->ruta,
            'permiso' => $item->permiso,
            'es_grupo' => $item->ruta === null,
            'seccion' => $item->seccion,
            'orden' => $item->orden,
            'activo' => $item->activo,
            'roles' => $item->roles->pluck('name')->sort()->values()->all(),
        ];
    }
}
