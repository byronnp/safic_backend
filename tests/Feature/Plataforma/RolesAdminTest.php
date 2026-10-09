<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Usuarios\Models\SolicitudRol;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;

function tokenRolesPlataforma(Rol $rol = Rol::SuperAdmin): string
{
    return auth('api')->tokenById(User::factory()->dePlataforma($rol)->create()->id);
}

function permisosDe(string $clave): array
{
    return Role::query()->whereNull('condominio_id')->where('name', $clave)->firstOrFail()->permissions->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->api = fn () => $this->withToken(tokenRolesPlataforma());
});

it('lista la matriz de roles de condominio con sus bloqueos y sin roles de plataforma', function () {
    $r = ($this->api)()->getJson('/api/v1/plataforma/roles')->assertOk();

    $roles = collect($r->json('data.roles'))->keyBy('clave');
    expect($roles->keys()->all())->toContain('administrador', 'contador', 'residente')->not->toContain('super_admin', 'soporte')
        ->and($roles['contador']['bloqueos'])->toHaveKey('usuarios.gestionar')
        ->and($roles['residente']['bloqueos'])->toHaveKey('condominio.editar')
        ->and($roles['administrador']['obligatorios'])->toBe(['usuarios.gestionar'])
        ->and($roles['tesorero']['tipo'])->toBe('cargo')
        ->and(collect($r->json('data.permisos'))->pluck('clave'))->not->toContain('plataforma.roles');
});

it('cuenta en cuántos condominios se usa cada rol', function () {
    [$a, $b] = [Condominio::factory()->create(), Condominio::factory()->create()];
    usuarioConToken($a, Rol::Guardia);
    usuarioConToken($a, Rol::Guardia);
    usuarioConToken($b, Rol::Guardia);

    $guardia = collect(($this->api)()->getJson('/api/v1/plataforma/roles')->json('data.roles'))->firstWhere('clave', 'guardia');

    expect($guardia['condominios'])->toBe(2);
});

it('lista los pedidos de rol pendientes de todos los condominios', function () {
    $a = Condominio::factory()->create(['nombre' => 'Brisas']);
    [$admin] = usuarioConToken($a, Rol::Administrador);
    enCondominio($a, function () use ($admin) {
        SolicitudRol::create(['user_id' => $admin->id, 'nombre' => 'Conserje', 'descripcion' => 'Recibe paquetes']);
        SolicitudRol::create(['user_id' => $admin->id, 'nombre' => 'Otro', 'descripcion' => 'x', 'estado' => 'atendida']);
    });

    $sol = ($this->api)()->getJson('/api/v1/plataforma/roles')->json('data.solicitudes');

    expect($sol)->toHaveCount(1)->and($sol[0])->toMatchArray(['condominio' => 'Brisas', 'nombre' => 'Conserje']);
});

it('cambia los permisos de un rol y rige para el condominio', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => ['unidades.ver']])
        ->assertOk()->assertJsonPath('data.permisos', ['unidades.ver']);

    expect(permisosDe('guardia'))->toBe(['unidades.ver']);
});

it('no concede lo que las reglas fijas prohíben', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/contador/permisos', ['permisos' => ['unidades.editar']])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERMISO_BLOQUEADO');
    ($this->api)()->putJson('/api/v1/plataforma/roles/residente/permisos', ['permisos' => ['usuarios.gestionar']])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERMISO_BLOQUEADO');

    expect(permisosDe('contador'))->toBe([]);
});

it('el administrador no puede perder la gestión de usuarios', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/administrador/permisos', ['permisos' => ['unidades.ver']])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERMISO_OBLIGATORIO');
});

it('rechaza permisos de plataforma o inexistentes y roles de plataforma', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => ['plataforma.roles']])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERMISO_INVALIDO');
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => ['no.existe']])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERMISO_INVALIDO');
    ($this->api)()->putJson('/api/v1/plataforma/roles/super_admin/permisos', ['permisos' => []])
        ->assertNotFound();
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', [])->assertStatus(422);
});

it('crea un rol adicional con sus permisos y no repite nombres', function () {
    $r = ($this->api)()->postJson('/api/v1/plataforma/roles', ['nombre' => 'Asist. contable', 'permisos' => ['unidades.ver']])->assertCreated();

    expect($r->json('data'))->toMatchArray(['clave' => 'asist_contable', 'tipo' => 'adicional', 'permisos' => ['unidades.ver']]);

    ($this->api)()->postJson('/api/v1/plataforma/roles', ['nombre' => 'Asist contable', 'permisos' => []])->assertStatus(409);
    ($this->api)()->postJson('/api/v1/plataforma/roles', ['nombre' => 'Guardia', 'permisos' => []])->assertStatus(409);
});

it('un rol adicional aparece en la matriz y se edita sin bloqueos', function () {
    ($this->api)()->postJson('/api/v1/plataforma/roles', ['nombre' => 'Conserje', 'permisos' => []])->assertCreated();
    ($this->api)()->putJson('/api/v1/plataforma/roles/conserje/permisos', ['permisos' => ['unidades.ver', 'unidades.editar']])->assertOk();

    expect(permisosDe('conserje'))->toBe(['unidades.editar', 'unidades.ver']);
});

it('solo quien gestiona roles de plataforma entra', function () {
    $this->withToken(tokenRolesPlataforma(Rol::Cobranza))->getJson('/api/v1/plataforma/roles')->assertForbidden();
});

it('un administrador de condominio no entra', function () {
    [, $token] = usuarioConToken(Condominio::factory()->create(), Rol::Administrador);
    $this->withToken($token)->getJson('/api/v1/plataforma/roles')->assertForbidden();
});
