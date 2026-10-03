<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;

function loginComo(User $user): array
{
    $user->update(['password' => 'Clave-Segura-2026']);

    return test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Clave-Segura-2026',
    ])->assertOk()->json('data.usuario');
}

it('el super admin entra sin condominios y recibe su perfil de plataforma', function () {
    $superAdmin = User::factory()->dePlataforma()->create();

    $usuario = loginComo($superAdmin);

    expect($usuario['condominios'])->toBe([])
        ->and($usuario['plataforma']['roles'])->toBe([Rol::SuperAdmin->value])
        ->and($usuario['plataforma']['permisos'])->toContain('plataforma.condominios', 'plataforma.roles', 'plataforma.cobranza');
});

it('soporte solo recibe los permisos de su rol de plataforma', function () {
    $soporte = User::factory()->dePlataforma(Rol::Soporte)->create();

    $usuario = loginComo($soporte);

    expect($usuario['plataforma']['permisos'])->toBe(['plataforma.condominios']);
});

it('un usuario de condominio no tiene perfil de plataforma', function () {
    $condominio = Condominio::factory()->create();
    $administrador = User::factory()->miembroDe($condominio, Rol::Administrador)->create();

    $usuario = loginComo($administrador);

    expect($usuario['plataforma'])->toBeNull()
        ->and($usuario['condominios'])->toHaveCount(1);
});

it('los permisos de plataforma no se mezclan con los del condominio', function () {
    $condominio = Condominio::factory()->create();
    [$residente, $token] = usuarioConToken($condominio, Rol::Residente);
    $anterior = getPermissionsTeamId();
    setPermissionsTeamId(Rol::EQUIPO_PLATAFORMA);
    $residente->assignRole(Rol::Soporte->value);
    setPermissionsTeamId($anterior);

    $contexto = $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->getJson('/api/v1/me/contexto')
        ->assertOk()
        ->json('data');

    expect($contexto['roles'])->toBe([Rol::Residente->value])
        ->and($contexto['permisos'])->not->toContain('plataforma.condominios');
});

it('el super admin no entra a un condominio por el header sin membresía', function () {
    $condominio = Condominio::factory()->create();
    $superAdmin = User::factory()->dePlataforma()->create();
    $token = auth('api')->tokenById($superAdmin->id);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->getJson('/api/v1/me/contexto')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONDOMINIO_NO_PERMITIDO');
});
