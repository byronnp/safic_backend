<?php

use App\Core\Auth\Services\InvitacionService;
use App\Core\Privacy\Models\AceptacionPrivacidad;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;

/*
| Primer ingreso por invitación: el administrador crea su contraseña y entra.
*/

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['nombre' => 'Conjunto Los Arupos']);
    $this->user = User::factory()->inactivo()->create(['name' => 'María Rivas', 'email' => 'maria@losarupos.ec']);
    $this->user->membresias()->create(['condominio_id' => $this->condominio->id, 'es_principal' => true, 'activo' => true]);
    $this->token = app(InvitacionService::class)->crear($this->user, $this->condominio);
});

it('muestra a quién corresponde la invitación', function () {
    $this->getJson("/api/v1/auth/invitaciones/{$this->token}")
        ->assertOk()
        ->assertJsonPath('data.nombre', 'María Rivas')
        ->assertJsonPath('data.email', 'maria@losarupos.ec')
        ->assertJsonPath('data.condominio', 'Conjunto Los Arupos')
        ->assertJsonPath('data.aviso_privacidad_version', config('safic.aviso_privacidad_version'));
});

it('rechaza un token inventado', function () {
    $this->getJson('/api/v1/auth/invitaciones/'.str_repeat('x', 64))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'INVITACION_INVALIDA');
});

it('rechaza una invitación vencida', function () {
    $this->travel(InvitacionService::DIAS_VIGENCIA + 1)->days();

    $this->getJson("/api/v1/auth/invitaciones/{$this->token}")->assertNotFound();
});

it('crea la contraseña, activa la cuenta y permite iniciar sesión', function () {
    $this->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", [
        'password' => 'Arupos-2026-seguro',
        'password_confirmation' => 'Arupos-2026-seguro',
        'acepta_privacidad' => true,
    ])->assertOk()->assertJsonPath('data.email', 'maria@losarupos.ec');

    expect($this->user->fresh()->activo)->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['email' => 'maria@losarupos.ec', 'password' => 'Arupos-2026-seguro'])
        ->assertOk()
        ->assertJsonPath('data.usuario.condominios.0.nombre', 'Conjunto Los Arupos');
});

it('el enlace solo se usa una vez', function () {
    $datos = ['password' => 'Arupos-2026-seguro', 'password_confirmation' => 'Arupos-2026-seguro', 'acepta_privacidad' => true];

    $this->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", $datos)->assertOk();
    $this->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", $datos)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'INVITACION_INVALIDA');
});

it('exige una contraseña segura y confirmada', function (string $password, string $confirmacion) {
    $this->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", [
        'password' => $password,
        'password_confirmation' => $confirmacion,
        'acepta_privacidad' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('password', 'error.fields');

    expect($this->user->fresh()->activo)->toBeFalse();
})->with([
    'corta' => ['abc123', 'abc123'],
    'sin números' => ['solo-letras-larga', 'solo-letras-larga'],
    'no coincide' => ['Arupos-2026-seguro', 'Otra-2026-clave'],
]);

it('una invitación nueva anula la anterior', function () {
    $nuevo = app(InvitacionService::class)->crear($this->user, $this->condominio);

    $this->getJson("/api/v1/auth/invitaciones/{$this->token}")->assertNotFound();
    $this->getJson("/api/v1/auth/invitaciones/{$nuevo}")->assertOk();
});

it('sin aceptar el aviso de privacidad no crea la cuenta', function () {
    $this->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", [
        'password' => 'Arupos-2026-seguro',
        'password_confirmation' => 'Arupos-2026-seguro',
        'acepta_privacidad' => false,
    ])->assertUnprocessable()
        ->assertJsonPath('error.fields.acepta_privacidad.0', 'Para continuar, acepta el aviso de privacidad.');

    expect($this->user->fresh()->activo)->toBeFalse()
        ->and(AceptacionPrivacidad::count())->toBe(0);
});

it('registra la aceptación del aviso con versión, fecha, IP y navegador', function () {
    $this->withHeader('User-Agent', 'Prueba/1.0')
        ->postJson("/api/v1/auth/invitaciones/{$this->token}/aceptar", [
            'password' => 'Arupos-2026-seguro',
            'password_confirmation' => 'Arupos-2026-seguro',
            'acepta_privacidad' => true,
        ])->assertOk();

    $aceptacion = AceptacionPrivacidad::query()->sole();
    expect($aceptacion->user_id)->toBe($this->user->id)
        ->and($aceptacion->version)->toBe(config('safic.aviso_privacidad_version'))
        ->and($aceptacion->origen)->toBe('invitacion')
        ->and($aceptacion->ip)->not->toBeNull()
        ->and($aceptacion->user_agent)->toBe('Prueba/1.0');
});
