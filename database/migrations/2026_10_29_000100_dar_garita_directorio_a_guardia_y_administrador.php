<?php

use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * El seeder solo da los permisos por defecto a los roles que crea, así que los
 * roles que ya existen no reciben el permiso nuevo. Esta migración se lo da al
 * guardia y al administrador (solo a ellos; el resto de ajustes del super admin se respeta).
 * Los condominios nuevos ya nacen con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permiso = Permission::findOrCreate(Permiso::GaritaDirectorio->value, 'api');

        $this->roles()->each(fn (Role $rol) => $rol->givePermissionTo($permiso));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permiso = Permission::query()->where('name', Permiso::GaritaDirectorio->value)->where('guard_name', 'api')->first();

        if ($permiso !== null) {
            $this->roles()->each(fn (Role $rol) => $rol->revokePermissionTo($permiso));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return Collection<int, Role> */
    private function roles(): Collection
    {
        return Role::query()
            ->whereNull('condominio_id')
            ->where('guard_name', 'api')
            ->whereIn('name', [Rol::Guardia->value, Rol::Administrador->value])
            ->get();
    }
};
