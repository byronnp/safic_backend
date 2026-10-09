<?php

use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\MenuSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Cada perfil ve su menú. Una hoja aparece solo si está asignada a uno de los
| perfiles del usuario en el equipo activo Y el usuario tiene su permiso.
*/

function menuDe(string $token, Condominio $condominio): array
{
    app('auth')->forgetGuards();

    return test()->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->getJson('/api/v1/me/menu')
        ->assertOk()
        ->json('data');
}

function rolGlobal(Rol $rol): Role
{
    return Role::query()->whereNull('condominio_id')->where('name', $rol->value)->firstOrFail();
}

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->condominio = Condominio::factory()->create();
});

it('el administrador ve inicio, unidades con bloques y su configuración', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(menuDe($token, $this->condominio))->toBe([
        ['id' => 'inicio', 'etiqueta' => 'Inicio', 'icono' => 'sym_r_space_dashboard', 'ruta' => 'inicio'],
        ['id' => 'unidades', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'hijos' => [
            ['id' => 'unidades.lista', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'ruta' => 'unidades', 'permiso' => 'unidades.ver'],
            ['id' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => 'unidades.ver'],
        ]],
        ['id' => 'configuracion', 'etiqueta' => 'Configuración', 'icono' => 'sym_r_settings', 'seccion' => true, 'hijos' => [
            ['id' => 'configuracion.condominio', 'etiqueta' => 'Datos del condominio', 'icono' => 'sym_r_domain', 'ruta' => 'configuracion-condominio', 'permiso' => 'condominio.editar'],
            ['id' => 'configuracion.cobro', 'etiqueta' => 'Cobro de cuotas', 'icono' => 'sym_r_request_quote', 'ruta' => 'configuracion-cobro', 'permiso' => 'condominio.editar'],
            ['id' => 'configuracion.usuarios', 'etiqueta' => 'Usuarios', 'icono' => 'sym_r_manage_accounts', 'ruta' => 'configuracion-usuarios', 'permiso' => 'usuarios.gestionar'],
        ]],
    ]);
});

it('ninguna pantalla en vista previa llega al menú de producción', function () {
    // Solo rutas que ya tienen pantalla con API; una vista previa se suma cuando pasa a datos reales
    $rutas = MenuItem::query()->whereNotNull('ruta')->pluck('ruta')->sort()->values()->all();

    expect($rutas)->toBe(['bloques', 'configuracion-cobro', 'configuracion-condominio', 'configuracion-usuarios', 'inicio', 'plataforma-condominios', 'unidades']);
});

it('un perfil sin permisos de configuración no ve esa sección', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->not->toContain('configuracion');
});

it('el residente solo ve inicio', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Residente);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('oculta una hoja asignada si al usuario le falta el permiso', function () {
    rolGlobal(Rol::Administrador)->revokePermissionTo(Permiso::UnidadesVer->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'configuracion']);
});

it('oculta una hoja no asignada al perfil aunque tenga el permiso', function () {
    MenuItem::query()->whereIn('clave', ['unidades.lista', 'unidades.bloques'])->get()
        ->each(fn (MenuItem $item) => $item->roles()->detach(rolGlobal(Rol::Guardia)->id));
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('oculta un módulo inactivo con sus hojas', function () {
    MenuItem::query()->where('clave', 'unidades')->update(['activo' => false]);
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'configuracion']);
});

it('arma el menú con el perfil que el usuario tiene en el condominio del header', function () {
    $otro = Condominio::factory()->create();
    [$user, $token] = usuarioConToken($this->condominio, Rol::Administrador);
    $user->membresias()->create(['condominio_id' => $otro->id, 'es_principal' => false, 'activo' => true]);
    setPermissionsTeamId($otro->id);
    $user->unsetRelation('roles')->assignRole(Rol::Residente->value);
    setPermissionsTeamId(null);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'unidades', 'configuracion'])
        ->and(array_column(menuDe($token, $otro), 'id'))->toBe(['inicio']);
});

it('un usuario sin perfil en el condominio recibe un menú vacío', function () {
    [, $token] = usuarioConToken($this->condominio, null);

    expect(menuDe($token, $this->condominio))->toBe([]);
});

describe('menú de plataforma', function () {
    beforeEach(function () {
        // Condominios viene sembrado para los perfiles que tienen su permiso (super admin y soporte)
        $this->itemPlataforma = MenuItem::query()->where('clave', 'plataforma.condominios')->firstOrFail();
    });

    it('el super admin ve su menú de plataforma', function () {
        $user = User::factory()->dePlataforma()->create();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'plataforma.condominios');
    });

    it('soporte ve Condominios por defecto y no lo ve si se le quita la hoja', function () {
        $user = User::factory()->dePlataforma(Rol::Soporte)->create();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'plataforma.condominios');

        $this->itemPlataforma->roles()->detach(rolGlobal(Rol::Soporte)->id);
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });

    it('el menú del condominio no incluye ítems de plataforma', function () {
        [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

        expect(array_column(menuDe($token, $this->condominio), 'id'))->not->toContain('plataforma.condominios');
    });

    it('un usuario de condominio no pide el menú de plataforma', function () {
        [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

        $this->withToken($token)
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertForbidden();
    });
});
