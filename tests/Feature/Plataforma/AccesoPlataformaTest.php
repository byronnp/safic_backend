<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;

/*
| Rutas /api/v1/plataforma/*: solo perfiles de plataforma (equipo 0), cada ruta
| con su permiso de plataforma. Los roles de un condominio no sirven aquí.
*/

function tokenDePlataforma(Rol $rol = Rol::SuperAdmin): string
{
    $user = User::factory()->dePlataforma($rol)->create();

    return auth('api')->tokenById($user->id);
}

it('el super admin entra a las rutas de plataforma', function () {
    $this->withToken(tokenDePlataforma())
        ->getJson('/api/v1/plataforma/planes')
        ->assertOk();
});

it('soporte entra con su permiso de plataforma', function () {
    $this->withToken(tokenDePlataforma(Rol::Soporte))
        ->getJson('/api/v1/plataforma/catalogos')
        ->assertOk();
});

it('cobranza no entra a una ruta que exige otro permiso de plataforma', function () {
    $this->withToken(tokenDePlataforma(Rol::Cobranza))
        ->getJson('/api/v1/plataforma/catalogos')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'SIN_PERMISO');
});

it('un administrador de condominio no entra al panel de plataforma', function () {
    $condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($condominio, Rol::Administrador);

    $this->withToken($token)
        ->getJson('/api/v1/plataforma/planes')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'SIN_PERMISO');
});

it('el header X-Condominio-Id no da acceso a la plataforma', function () {
    $condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($condominio, Rol::Administrador);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->getJson('/api/v1/plataforma/planes')
        ->assertForbidden();
});

it('exige sesión', function () {
    $this->getJson('/api/v1/plataforma/planes')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'NO_AUTENTICADO');
});
