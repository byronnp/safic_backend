<?php

namespace Database\Seeders;

use App\Core\Access\Catalogo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Carga a la base el catálogo de permisos y roles que está en database/data/acceso.php. Idempotente: se puede correr en cada despliegue.
 *
 * - La metadata (etiquetas, ámbito, reglas) se actualiza siempre desde el archivo.
 * - Los roles son globales (condominio_id nulo). Los permisos por defecto de un rol solo se aplican al crearlo, y un permiso que nace
 *   ahora se concede únicamente a los roles que lo listan, sin tocar lo que el super admin ajustó en el resto.
 *
 * Un permiso, un rol o una regla nuevos son una línea en el archivo de datos: no hay que tocar el código.
 */
class RolesYPermisosSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{permisos: list<array<string, mixed>>, roles: list<array<string, mixed>>} $datos */
        $datos = require database_path('data/acceso.php');

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $anterior = getPermissionsTeamId();
        setPermissionsTeamId(null);

        /** @var list<string> $nuevos */
        $nuevos = [];
        foreach ($datos['permisos'] as $p) {
            $permiso = Permission::findOrCreate((string) $p['clave'], 'api');
            if ($permiso->wasRecentlyCreated) {
                $nuevos[] = (string) $p['clave'];
            }
            $permiso->forceFill([
                'etiqueta' => $p['etiqueta'], 'grupo' => $p['grupo'], 'ambito' => $p['ambito'],
                'administrativo' => $p['administrativo'], 'escritura' => $p['escritura'],
            ])->save();
        }

        foreach ($datos['roles'] as $r) {
            $rol = Role::query()->whereNull('condominio_id')->where('name', $r['clave'])->where('guard_name', 'api')->first();
            $creado = $rol === null;
            $rol ??= Role::create(['name' => $r['clave'], 'guard_name' => 'api', 'condominio_id' => null]);

            $rol->forceFill([
                'etiqueta' => $r['etiqueta'], 'ambito' => $r['ambito'], 'es_cargo' => $r['es_cargo'], 'cuenta_cupo' => $r['cuenta_cupo'],
                'asignable' => $r['asignable'], 'requiere_vigencia' => $r['requiere_vigencia'], 'exige_doble_factor' => $r['exige_doble_factor'], 'solo_lectura' => $r['solo_lectura'],
                'sin_administrativos' => $r['sin_administrativos'], 'sistema' => true, 'orden' => $r['orden'],
            ])->save();

            DB::table('rol_permisos_obligatorios')->where('role_id', $rol->id)->delete();
            $ids = Permission::query()->whereIn('name', $r['obligatorios'])->where('guard_name', 'api')->pluck('id');
            DB::table('rol_permisos_obligatorios')->insert($ids->map(fn (int $id): array => ['role_id' => $rol->id, 'permission_id' => $id])->all());

            if ($creado) {
                $rol->syncPermissions($r['permisos']);

                continue;
            }
            $concedibles = array_values(array_intersect($nuevos, $r['permisos']));
            if ($concedibles !== []) {
                $rol->givePermissionTo($concedibles);
            }
        }

        setPermissionsTeamId($anterior);
        $registrar->forgetCachedPermissions();
        app(Catalogo::class)->olvidar();
    }
}
