<?php

namespace Database\Seeders;

use App\Core\Access\Catalogo;
use App\Core\Menu\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga a la base el menú del sistema que está en database/data/menu.php. Idempotente: solo crea los ítems que faltan y solo asigna
 * roles a los ítems nuevos, para no pisar lo que el super admin cambió desde «Menú del sistema».
 *
 * Aquí van solo las pantallas que ya tienen API (una pantalla en vista previa no se agrega). Cuando una pasa a datos reales, se suma su
 * ítem al archivo de datos (mismo `clave` que el `id` del frontend) y se corre este seeder: no hay que tocar el código.
 *
 * Por defecto, una hoja nueva se asigna a cada rol del ámbito que hoy tiene el permiso de la hoja (o a todos los roles del ámbito si la
 * hoja no pide permiso).
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{condominio: list<array<string, mixed>>, plataforma: list<array<string, mixed>>} $datos */
        // El archivo de datos se puede apuntar a otro (pruebas); por defecto es database/data/menu.php
        $datos = require config('safic.archivo_de_menu') ?? database_path('data/menu.php');

        $this->sembrar($datos['condominio'], MenuItem::AMBITO_CONDOMINIO, null);
        $this->sembrar($datos['plataforma'], MenuItem::AMBITO_PLATAFORMA, null);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sembrar(array $items, string $ambito, ?MenuItem $padre): void
    {
        foreach ($items as $orden => $definicion) {
            $permiso = isset($definicion['permiso']) ? (string) $definicion['permiso'] : null;

            $item = MenuItem::query()->firstOrCreate(['clave' => $definicion['clave']], [
                'padre_id' => $padre?->id,
                'ambito' => $ambito,
                'etiqueta' => $definicion['etiqueta'],
                'icono' => $definicion['icono'],
                'ruta' => $definicion['ruta'] ?? null,
                'permiso' => $permiso,
                'seccion' => $definicion['seccion'] ?? false,
                'orden' => ($orden + 1) * 10,
            ]);

            if ($item->wasRecentlyCreated && isset($definicion['ruta'])) {
                $item->roles()->sync($this->perfilesPara($ambito, $permiso));
            }

            if (isset($definicion['hijos'])) {
                /** @var list<array<string, mixed>> $hijos */
                $hijos = $definicion['hijos'];
                $this->sembrar($hijos, $ambito, $item);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function perfilesPara(string $ambito, ?string $permiso): array
    {
        $deAmbito = array_map(fn ($r) => $r->id, array_filter(
            app(Catalogo::class)->roles($ambito === MenuItem::AMBITO_PLATAFORMA ? Catalogo::PLATAFORMA : Catalogo::CONDOMINIO),
            fn ($r) => $r->sistema,
        ));
        if ($permiso === null) {
            return array_values($deAmbito);
        }

        /** @var list<int> $conPermiso */
        $conPermiso = DB::table('role_has_permissions as rhp')->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('p.name', $permiso)->whereIn('rhp.role_id', $deAmbito)->pluck('rhp.role_id')->map(fn ($id): int => (int) $id)->all();

        return $conPermiso;
    }
}
