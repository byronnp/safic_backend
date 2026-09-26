<?php

use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    $this->user = User::factory()->miembroDe($this->condominio)->create([
        'email' => 'maria@ejemplo.ec',
        'password' => 'Clave-Segura-2026',
    ]);
});

function iniciarSesion(array $datos = [], array $headers = []): TestResponse
{
    return test()->withHeaders($headers)->postJson('/api/v1/auth/login', $datos + [
        'email' => 'maria@ejemplo.ec',
        'password' => 'Clave-Segura-2026',
    ]);
}

it('inicia sesión y entrega access token y cookie de refresh', function () {
    $response = iniciarSesion()
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in', 'usuario' => ['id', 'nombre', 'email', 'condominios']]])
        ->assertJsonPath('data.expires_in', 15 * 60)
        ->assertJsonMissingPath('data.refresh_token')
        ->assertPlainCookie('safic_refresh');

    expect($response->json('data.usuario.condominios'))->toHaveCount(1);
});

it('rechaza credenciales incorrectas', function () {
    iniciarSesion(['password' => 'otra'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'CREDENCIALES_INVALIDAS');
});

it('rechaza usuarios inactivos', function () {
    $this->user->update(['activo' => false]);

    iniciarSesion()->assertUnauthorized();
});

it('entrega el refresh token en el cuerpo para la app móvil', function () {
    iniciarSesion(headers: ['X-Client-Type' => 'mobile'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['refresh_token']]);
});

it('rota el refresh token y detecta su reutilización', function () {
    $primero = iniciarSesion()->getCookie('safic_refresh', false)->getValue();

    $segundo = $this->withCredentials()->withUnencryptedCookie('safic_refresh', $primero)
        ->postJson('/api/v1/auth/refresh')
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token']])
        ->getCookie('safic_refresh', false)->getValue();

    expect($segundo)->not->toBe($primero);

    // Reusar el primero revoca toda la familia…
    $this->withCredentials()->withUnencryptedCookie('safic_refresh', $primero)
        ->postJson('/api/v1/auth/refresh')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'REFRESH_REUTILIZADO');

    // …así que el segundo tampoco sirve.
    $this->withCredentials()->withUnencryptedCookie('safic_refresh', $segundo)
        ->postJson('/api/v1/auth/refresh')
        ->assertUnauthorized();
});

it('devuelve el usuario con sus condominios activos', function () {
    $token = iniciarSesion()->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'maria@ejemplo.ec')
        ->assertJsonPath('data.condominios.0.id', $this->condominio->id);
});

it('cierra sesión e invalida el access token', function () {
    $token = iniciarSesion()->json('data.access_token');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'NO_AUTENTICADO');
});

it('responde errores con el formato común', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJsonStructure(['error' => ['code', 'message']]);
});
