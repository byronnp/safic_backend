<?php

namespace Database\Seeders;

use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Menú inicial del sistema. Idempotente: solo crea los ítems que faltan y solo
 * asigna perfiles a los ítems nuevos, para no pisar lo que el super admin cambió.
 *
 * Aquí van solo las pantallas que ya tienen API. Las pantallas en vista previa
 * las agrega el frontend en desarrollo; cuando una pasa a datos reales, se suma
 * su ítem a este archivo (mismo `clave` que el `id` del frontend).
 *
 * Por defecto, una hoja se asigna a cada perfil cuyo conjunto de permisos por
 * defecto incluye el permiso de la hoja (o a todos los perfiles del ámbito si
 * la hoja no pide permiso).
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar($this->menuCondominio(), MenuItem::AMBITO_CONDOMINIO, null);
        $this->sembrar($this->menuPlataforma(), MenuItem::AMBITO_PLATAFORMA, null);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sembrar(array $items, string $ambito, ?MenuItem $padre): void
    {
        foreach ($items as $orden => $definicion) {
            /** @var Permiso|null $permiso */
            $permiso = $definicion['permiso'] ?? null;

            $item = MenuItem::query()->firstOrCreate(['clave' => $definicion['clave']], [
                'padre_id' => $padre?->id,
                'ambito' => $ambito,
                'etiqueta' => $definicion['etiqueta'],
                'icono' => $definicion['icono'],
                'ruta' => $definicion['ruta'] ?? null,
                'permiso' => $permiso?->value,
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
    private function perfilesPara(string $ambito, ?Permiso $permiso): array
    {
        $roles = array_filter(Rol::cases(), fn (Rol $rol) => $rol->esDePlataforma() === ($ambito === MenuItem::AMBITO_PLATAFORMA)
            && ($permiso === null || in_array($permiso, $rol->permisosPorDefecto(), true)));

        /** @var list<int> $ids */
        $ids = Role::query()
            ->whereNull('condominio_id')
            ->where('guard_name', 'api')
            ->whereIn('name', array_map(fn (Rol $rol) => $rol->value, $roles))
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuCondominio(): array
    {
        return [
            ['clave' => 'inicio', 'etiqueta' => 'Inicio', 'icono' => 'sym_r_space_dashboard', 'ruta' => 'inicio'],
            [
                'clave' => 'unidades', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment',
                'hijos' => [
                    ['clave' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => Permiso::UnidadesVer],
                ],
            ],
        ];
    }

    /**
     * El panel de plataforma todavía no tiene pantallas con API: se suman aquí
     * a medida que pasan de vista previa a datos reales.
     *
     * @return list<array<string, mixed>>
     */
    private function menuPlataforma(): array
    {
        return [];
    }
}
