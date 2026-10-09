<?php

namespace Database\Seeders;

use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincroniza el catálogo de permisos y roles desde el código.
 * Idempotente: se puede correr en cada despliegue.
 * Los roles son globales (condominio_id nulo); los permisos por defecto solo
 * se aplican al crear el rol, para no pisar lo que el super admin ajustó.
 */
class RolesYPermisosSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId(null);

        /** @var list<Permiso> $nuevos */
        $nuevos = [];
        foreach (Permiso::cases() as $permiso) {
            if (Permission::findOrCreate($permiso->value, 'api')->wasRecentlyCreated) {
                $nuevos[] = $permiso;
            }
        }

        foreach (Rol::cases() as $rol) {
            $existente = Role::query()->whereNull('condominio_id')->where('name', $rol->value)->where('guard_name', 'api')->first();

            if ($existente === null) {
                $nuevo = Role::create(['name' => $rol->value, 'guard_name' => 'api', 'condominio_id' => null]);
                $nuevo->syncPermissions(array_map(fn (Permiso $p) => $p->value, $rol->permisosPorDefecto()));

                continue;
            }

            // Un permiso que nace ahora se concede a los roles que lo traen por defecto,
            // sin tocar lo que el super admin ya ajustó en el resto.
            $concedibles = array_values(array_filter($nuevos, fn (Permiso $p) => in_array($p, $rol->permisosPorDefecto(), true)));
            if ($concedibles !== []) {
                $existente->givePermissionTo(array_map(fn (Permiso $p) => $p->value, $concedibles));
            }
        }

        setPermissionsTeamId($anterior);
        $registrar->forgetCachedPermissions();
    }
}
