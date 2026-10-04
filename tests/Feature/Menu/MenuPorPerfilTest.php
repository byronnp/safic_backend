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

it('el administrador ve inicio y unidades con bloques', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(menuDe($token, $this->condominio))->toBe([
        ['id' => 'inicio', 'etiqueta' => 'Inicio', 'icono' => 'sym_r_space_dashboard', 'ruta' => 'inicio'],
        ['id' => 'unidades', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'hijos' => [
            ['id' => 'unidades.lista', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'ruta' => 'unidades', 'permiso' => 'unidades.ver'],
            ['id' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => 'unidades.ver'],
        ]],
    ]);
});

it('el residente solo ve inicio', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Residente);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('oculta una hoja asignada si al usuario le falta el permiso', function () {
    rolGlobal(Rol::Administrador)->revokePermissionTo(Permiso::UnidadesVer->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
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

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('arma el menú con el perfil que el usuario tiene en el condominio del header', function () {
    $otro = Condominio::factory()->create();
    [$user, $token] = usuarioConToken($this->condominio, Rol::Administrador);
    $user->membresias()->create(['condominio_id' => $otro->id, 'es_principal' => false, 'activo' => true]);
    setPermissionsTeamId($otro->id);
    $user->unsetRelation('roles')->assignRole(Rol::Residente->value);
    setPermissionsTeamId(null);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'unidades'])
        ->and(array_column(menuDe($token, $otro), 'id'))->toBe(['inicio']);
});

it('un usuario sin perfil en el condominio recibe un menú vacío', function () {
    [, $token] = usuarioConToken($this->condominio, null);

    expect(menuDe($token, $this->condominio))->toBe([]);
});

describe('menú de plataforma', function () {
    beforeEach(function () {
        $this->itemPlataforma = MenuItem::query()->create([
            'clave' => 'plataforma.condominios', 'ambito' => MenuItem::AMBITO_PLATAFORMA, 'etiqueta' => 'Condominios',
            'icono' => 'sym_r_location_city', 'ruta' => 'plataforma-condominios', 'permiso' => Permiso::PlataformaCondominios->value,
        ]);
        $this->itemPlataforma->roles()->sync([rolGlobal(Rol::SuperAdmin)->id]);
    });

    it('el super admin ve su menú de plataforma', function () {
        $user = User::factory()->dePlataforma()->create();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'plataforma.condominios');
    });

    it('soporte no ve un ítem que no tiene asignado', function () {
        $user = User::factory()->dePlataforma(Rol::Soporte)->create();

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
