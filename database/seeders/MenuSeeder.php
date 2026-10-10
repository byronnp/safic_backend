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
 * Aquí van solo las pantallas que ya tienen API (una pantalla en vista previa no se agrega). Las pantallas en vista previa
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
                    ['clave' => 'unidades.lista', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'ruta' => 'unidades', 'permiso' => Permiso::UnidadesVer],
                    ['clave' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => Permiso::UnidadesVer],
                ],
            ],
            [
                'clave' => 'finanzas', 'etiqueta' => 'Finanzas', 'icono' => 'sym_r_account_balance_wallet',
                'hijos' => [
                    ['clave' => 'finanzas.resumen', 'etiqueta' => 'Resumen', 'icono' => 'sym_r_dashboard', 'ruta' => 'finanzas-resumen', 'permiso' => Permiso::FinanzasVer],
                ],
            ],
            [
                'clave' => 'configuracion', 'etiqueta' => 'Configuración', 'icono' => 'sym_r_settings', 'seccion' => true,
                'hijos' => [
                    ['clave' => 'configuracion.condominio', 'etiqueta' => 'Datos del condominio', 'icono' => 'sym_r_domain', 'ruta' => 'configuracion-condominio', 'permiso' => Permiso::CondominioEditar],
                    ['clave' => 'configuracion.cobro', 'etiqueta' => 'Cobro de cuotas', 'icono' => 'sym_r_request_quote', 'ruta' => 'configuracion-cobro', 'permiso' => Permiso::CondominioEditar],
                    ['clave' => 'configuracion.amenidades', 'etiqueta' => 'Amenidades', 'icono' => 'sym_r_pool', 'ruta' => 'configuracion-amenidades', 'permiso' => Permiso::AmenidadesGestionar],
                    ['clave' => 'configuracion.usuarios', 'etiqueta' => 'Usuarios', 'icono' => 'sym_r_manage_accounts', 'ruta' => 'configuracion-usuarios', 'permiso' => Permiso::UsuariosGestionar],
                    ['clave' => 'configuracion.roles', 'etiqueta' => 'Roles', 'icono' => 'sym_r_admin_panel_settings', 'ruta' => 'configuracion-roles', 'permiso' => Permiso::UsuariosGestionar],
                ],
            ],
        ];
    }

    /**
     * Solo las pantallas del panel de plataforma que ya tienen API: se suman aquí a
     * medida que pasan de vista previa a datos reales (una pantalla en vista previa
     * nunca debe llegar al menú de producción).
     *
     * @return list<array<string, mixed>>
     */
    private function menuPlataforma(): array
    {
        return [
            ['clave' => 'plataforma.condominios', 'etiqueta' => 'Condominios', 'icono' => 'sym_r_location_city', 'ruta' => 'plataforma-condominios', 'permiso' => Permiso::PlataformaCondominios],
            ['clave' => 'plataforma.amenidades', 'etiqueta' => 'Catálogo de amenidades', 'icono' => 'sym_r_category', 'ruta' => 'plataforma-amenidades', 'permiso' => Permiso::PlataformaCondominios],
            [
                'clave' => 'plataforma.acceso', 'etiqueta' => 'Acceso', 'icono' => 'sym_r_lock', 'seccion' => true,
                'hijos' => [
                    ['clave' => 'plataforma.roles', 'etiqueta' => 'Roles y permisos', 'icono' => 'sym_r_shield_person', 'ruta' => 'plataforma-roles', 'permiso' => Permiso::PlataformaRoles],
                    ['clave' => 'plataforma.menu', 'etiqueta' => 'Menú del sistema', 'icono' => 'sym_r_menu_open', 'ruta' => 'plataforma-menu', 'permiso' => Permiso::PlataformaRoles],
                ],
            ],
        ];
    }
}
