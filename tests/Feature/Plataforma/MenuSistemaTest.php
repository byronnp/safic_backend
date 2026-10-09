<?php

use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\MenuSeeder;

function tokenMenuPlataforma(Rol $rol = Rol::SuperAdmin): string
{
    return auth('api')->tokenById(User::factory()->dePlataforma($rol)->create()->id);
}

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->token = tokenMenuPlataforma();
    $this->api = fn () => $this->withToken($this->token);
});

it('lista el menú de un ámbito con sus perfiles, roles y permisos elegibles', function () {
    $r = ($this->api)()->getJson('/api/v1/plataforma/menu-sistema?ambito=condominio')->assertOk();

    $claves = collect($r->json('data.items'))->pluck('clave');
    expect($claves)->toContain('inicio', 'configuracion')
        ->and($claves)->not->toContain('plataforma.menu')
        ->and(collect($r->json('data.roles'))->pluck('clave'))->toContain('administrador')->not->toContain('super_admin')
        ->and(collect($r->json('data.permisos'))->pluck('clave'))->not->toContain('plataforma.roles');
});

it('exige el ámbito', function () {
    ($this->api)()->getJson('/api/v1/plataforma/menu-sistema')->assertStatus(422);
});

it('exige el permiso de roles de plataforma', function () {
    $this->withToken(tokenMenuPlataforma(Rol::Cobranza))->getJson('/api/v1/plataforma/menu-sistema?ambito=plataforma')->assertForbidden();
});

it('un administrador de condominio no entra al menú del sistema', function () {
    [, $token] = usuarioConToken(Condominio::factory()->create(), Rol::Administrador);
    $this->withToken($token)->getJson('/api/v1/plataforma/menu-sistema?ambito=condominio')->assertForbidden();
});

it('crea una pantalla con su orden, clave única y perfiles', function () {
    $grupo = MenuItem::query()->where('clave', 'configuracion')->firstOrFail();
    $datos = ['ambito' => 'condominio', 'padre_id' => $grupo->id, 'etiqueta' => 'Reglamento', 'icono' => 'sym_r_gavel', 'ruta' => 'reglamento', 'roles' => ['administrador']];

    $uno = ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', $datos)->assertCreated();
    $dos = ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', $datos)->assertCreated();

    expect($uno->json('data.clave'))->toBe('condominio.reglamento')
        ->and($dos->json('data.clave'))->toBe('condominio.reglamento-2')
        ->and($dos->json('data.orden'))->toBeGreaterThan($uno->json('data.orden'))
        ->and($uno->json('data.roles'))->toBe(['administrador']);
});

it('rechaza perfiles de otro menú, íconos fuera del catálogo y permisos de otro ámbito', function () {
    $base = ['ambito' => 'condominio', 'etiqueta' => 'Algo', 'icono' => 'sym_r_star', 'ruta' => 'algo'];

    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', $base + ['roles' => ['super_admin']])->assertStatus(422)->assertJsonPath('error.code', 'PERFIL_INVALIDO');
    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', ['icono' => 'star'] + $base)->assertStatus(422);
    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', $base + ['permiso' => 'plataforma.roles'])->assertStatus(422);
});

it('no permite un grupo dentro de otro grupo ni un padre que sea pantalla', function () {
    $grupo = MenuItem::query()->where('clave', 'configuracion')->firstOrFail();
    $hoja = MenuItem::query()->where('clave', 'inicio')->firstOrFail();

    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', ['ambito' => 'condominio', 'padre_id' => $grupo->id, 'etiqueta' => 'Grupo', 'icono' => 'sym_r_star'])->assertStatus(422);
    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', ['ambito' => 'condominio', 'padre_id' => $hoja->id, 'etiqueta' => 'X', 'icono' => 'sym_r_star', 'ruta' => 'x'])->assertStatus(422);
});

it('edita etiqueta y desactiva un ítem, sin dejar una pantalla sin ruta', function () {
    $hoja = MenuItem::query()->where('clave', 'inicio')->firstOrFail();

    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$hoja->id}", ['etiqueta' => 'Panel', 'activo' => false])
        ->assertOk()->assertJsonPath('data.etiqueta', 'Panel')->assertJsonPath('data.activo', false);
    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$hoja->id}", ['ruta' => null])->assertStatus(422);
    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$hoja->id}", ['ambito' => 'plataforma'])->assertStatus(422);
});

it('los perfiles se asignan a pantallas, no a grupos', function () {
    $grupo = MenuItem::query()->where('clave', 'configuracion')->firstOrFail();

    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$grupo->id}", ['roles' => ['administrador']])->assertStatus(422);
});

it('sube y baja un ítem entre sus hermanos y no pasa de los extremos', function () {
    $hijos = fn () => MenuItem::query()->where('padre_id', MenuItem::query()->where('clave', 'configuracion')->value('id'))->orderBy('orden')->pluck('clave')->all();
    $antes = $hijos();
    $segundo = MenuItem::query()->where('clave', $antes[1])->firstOrFail();

    ($this->api)()->postJson("/api/v1/plataforma/menu-sistema/{$segundo->id}/mover", ['direccion' => 'arriba'])->assertOk();
    expect($hijos()[0])->toBe($antes[1])->and($hijos()[1])->toBe($antes[0]);

    ($this->api)()->postJson("/api/v1/plataforma/menu-sistema/{$segundo->id}/mover", ['direccion' => 'arriba'])->assertOk();
    expect($hijos()[0])->toBe($antes[1]);

    ($this->api)()->postJson("/api/v1/plataforma/menu-sistema/{$segundo->id}/mover", ['direccion' => 'lado'])->assertStatus(422);
});

it('la vista previa muestra el menú de un perfil y lo que se le oculta', function () {
    $r = ($this->api)()->getJson('/api/v1/plataforma/menu-sistema/vista-previa?ambito=condominio&perfil=administrador')->assertOk();

    expect($r->json('data'))->toHaveKeys(['menu', 'ocultos']);
});
