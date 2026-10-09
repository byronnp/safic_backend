<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Menu\Models\MenuItem;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del super admin: subir o bajar un ítem entre sus hermanos (mismo grupo).
 * Si hay empates de orden se renumera antes de intercambiar.
 */
final class MoverMenuItemAction
{
    public function execute(int $id, string $direccion): MenuItem
    {
        return DB::transaction(function () use ($id, $direccion): MenuItem {
            $item = MenuItem::query()->lockForUpdate()->findOrFail($id);

            $hermanos = MenuItem::query()
                ->where('ambito', $item->ambito)
                ->where('padre_id', $item->padre_id)
                ->orderBy('orden')->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            // Orden limpio de 10 en 10, sin empates
            foreach ($hermanos as $i => $hermano) {
                $hermano->orden = ($i + 1) * 10;
            }

            $posicion = $hermanos->search(fn (MenuItem $h) => $h->id === $item->id);
            $destino = $direccion === 'arriba' ? $posicion - 1 : $posicion + 1;

            if ($destino >= 0 && $destino < $hermanos->count()) {
                [$hermanos[$posicion]->orden, $hermanos[$destino]->orden] = [$hermanos[$destino]->orden, $hermanos[$posicion]->orden];
            }

            $hermanos->each(fn (MenuItem $h) => $h->isDirty('orden') && $h->save());

            return $hermanos[$posicion];
        });
    }
}
