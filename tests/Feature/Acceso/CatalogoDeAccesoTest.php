<?php

use App\Core\Access\Catalogo;
use App\Core\Menu\Models\MenuItem;
use App\Modules\Plataforma\Actions\ListarRolesAdminAction;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Los permisos y roles que el archivo de datos define. */
function datosDeAcceso(): array
{
    return require database_path('data/acceso.php');
}

it('carga a la base cada permiso y cada rol del archivo de datos, con su etiqueta y sus reglas', function () {
    $datos = datosDeAcceso();
    $catalogo = app(Catalogo::class);

    expect(array_keys($catalogo->permisos()))->toEqualCanonicalizing(array_column($datos['permisos'], 'clave'))
        ->and(array_keys($catalogo->roles()))->toEqualCanonicalizing(array_column($datos['roles'], 'clave'));

    $p = $catalogo->permiso('gastos.pagar');
    expect($p->etiqueta)->not->toBe('')->and($p->grupo)->toBe('Finanzas')->and($p->esDePlataforma())->toBeFalse()->and($p->administrativo)->toBeTrue();
    expect($catalogo->permiso('plataforma.roles')->esDePlataforma())->toBeTrue();
});

it('los cargos de la directiva, los perfiles asignables y los que cuentan para el cupo salen de la base', function () {
    $catalogo = app(Catalogo::class);

    expect(array_map(fn ($r) => $r->clave, $catalogo->cargos()))->toBe(['presidente', 'vicepresidente', 'secretario', 'tesorero'])
        ->and(array_map(fn ($r) => $r->clave, $catalogo->asignables()))->toBe(['administrador', 'contador', 'guardia', 'mantenimiento'])
        ->and($catalogo->nombresQueCuentanParaCupo())->toEqualCanonicalizing(['administrador', 'contador', 'tesorero'])
        ->and($catalogo->nombresQueExigenDobleFactor())->toBe(['contador'])
        ->and($catalogo->rol('contador')->requiereVigencia)->toBeTrue();
});

it('ningún rol de condominio trae permisos de plataforma por defecto, ni al revés', function () {
    $catalogo = app(Catalogo::class);
    foreach (datosDeAcceso()['roles'] as $r) {
        foreach ($r['permisos'] as $clave) {
            $permiso = $catalogo->permiso($clave);
            expect($permiso)->not->toBeNull("{$r['clave']} lista un permiso que no existe: {$clave}");
            if ($r['clave'] !== 'super_admin') {
                expect($permiso->ambito)->toBe($r['ambito'], "{$r['clave']} trae {$clave} de otro ámbito");
            }
        }
    }
});

it('las reglas fijas de un rol vienen de la base: el contador no escribe, el guardia no administra', function () {
    $catalogo = app(Catalogo::class);

    expect($catalogo->rol('contador')->motivoBloqueo($catalogo->permiso('gastos.registrar')))->not->toBeNull()
        ->and($catalogo->rol('contador')->motivoBloqueo($catalogo->permiso('finanzas.ver')))->toBeNull()
        ->and($catalogo->rol('guardia')->motivoBloqueo($catalogo->permiso('unidades.editar')))->not->toBeNull()
        ->and($catalogo->rol('administrador')->motivoBloqueo($catalogo->permiso('plataforma.roles')))->not->toBeNull()
        ->and($catalogo->rol('administrador')->exige('usuarios.gestionar'))->toBeTrue();
});

it('un permiso nuevo es un dato: sale en la pantalla Roles sin tocar el código', function () {
    DB::table('permissions')->insert(['name' => 'inventario.ver', 'guard_name' => 'api', 'etiqueta' => 'Ver el inventario', 'grupo' => 'Inventario', 'ambito' => 'condominio', 'administrativo' => false, 'escritura' => false, 'created_at' => now(), 'updated_at' => now()]);
    app(Catalogo::class)->olvidar();
    expect(app(Catalogo::class)->permiso('inventario.ver')->etiqueta)->toBe('Ver el inventario');
    $claves = collect(app(ListarRolesAdminAction::class)->execute()['permisos'])->pluck('clave')->all();
    expect($claves)->toContain('inventario.ver');
});

it('un rol nuevo en la base entra al catálogo con su ámbito y sus reglas', function () {
    $rol = Role::create(['name' => 'conserje', 'guard_name' => 'api', 'condominio_id' => null]);
    $rol->forceFill(['etiqueta' => 'Conserje', 'ambito' => 'condominio', 'asignable' => true, 'sin_administrativos' => true, 'cuenta_cupo' => false, 'orden' => 75, 'sistema' => false])->save();
    app(Catalogo::class)->olvidar();

    $info = app(Catalogo::class)->rol('conserje');
    expect($info->etiqueta)->toBe('Conserje')->and($info->asignable)->toBeTrue()
        ->and(array_map(fn ($r) => $r->clave, app(Catalogo::class)->asignables()))->toContain('conserje')
        ->and($info->motivoBloqueo(app(Catalogo::class)->permiso('unidades.editar')))->not->toBeNull();
});

it('correr el seeder otra vez no pisa lo que el super admin ajustó en los permisos de un rol', function () {
    $rol = Role::query()->whereNull('condominio_id')->where('name', 'guardia')->firstOrFail();
    $rol->revokePermissionTo('unidades.ver');

    (new RolesYPermisosSeeder)->run();

    expect(Role::query()->whereNull('condominio_id')->where('name', 'guardia')->firstOrFail()->permissions->pluck('name')->all())->not->toContain('unidades.ver');
});

it('un permiso que nace en el archivo se concede solo a los roles que lo listan', function () {
    Permission::query()->where('name', 'asambleas.firmar')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (new RolesYPermisosSeeder)->run();

    $con = fn (string $rol) => Role::query()->whereNull('condominio_id')->where('name', $rol)->firstOrFail()->permissions->pluck('name')->all();
    expect($con('secretario'))->toContain('asambleas.firmar')->and($con('presidente'))->toContain('asambleas.firmar')
        ->and($con('guardia'))->not->toContain('asambleas.firmar')->and($con('administrador'))->not->toContain('asambleas.firmar');
});

it('el menú del archivo de datos se carga a la base y una pantalla nueva se agrega sin tocar el código', function () {
    (new MenuSeeder)->run();
    $antes = MenuItem::query()->count();
    $datos = require database_path('data/menu.php');
    expect($antes)->toBeGreaterThan(20)->and(MenuItem::query()->where('clave', 'asambleas.preparar')->value('permiso'))->toBe('asambleas.preparar');

    // Una pantalla nueva con su permiso: los roles que ya tienen ese permiso la reciben
    DB::table('permissions')->insert(['name' => 'inventario.ver', 'guard_name' => 'api', 'etiqueta' => 'Ver el inventario', 'grupo' => 'Inventario', 'ambito' => 'condominio', 'administrativo' => false, 'escritura' => false, 'created_at' => now(), 'updated_at' => now()]);
    Role::query()->whereNull('condominio_id')->where('name', 'administrador')->firstOrFail()->givePermissionTo('inventario.ver');
    app(Catalogo::class)->olvidar();
    $datos['condominio'][] = ['clave' => 'inventario', 'etiqueta' => 'Inventario', 'icono' => 'sym_r_inventory', 'ruta' => 'inventario', 'permiso' => 'inventario.ver'];
    $archivo = sys_get_temp_dir().'/menu-prueba.php';
    file_put_contents($archivo, '<?php return '.var_export($datos, true).';');
    config(['safic.archivo_de_menu' => $archivo]);

    (new MenuSeeder)->run();

    $item = MenuItem::query()->where('clave', 'inventario')->firstOrFail();
    expect($item->ruta)->toBe('inventario')->and($item->roles->pluck('name')->all())->toBe(['administrador']);
    @unlink($archivo);
});
