<?php

use App\Core\Auth\Models\Invitacion;
use App\Core\Auth\Services\InvitacionService;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Mail\InvitacionAdministradorMail;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Mail;

/*
| El super admin reenvía la invitación a un administrador que aún no creó su
| contraseña y, si hace falta, corrige su correo.
*/

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create(['nombre' => 'Conjunto Los Arupos']);
    $this->admin = User::factory()->inactivo()->miembroDe($this->condominio, Rol::Administrador)
        ->create(['name' => 'María Rivas', 'email' => 'maria@losarupos.ec']);
    $superAdmin = User::factory()->dePlataforma()->create();
    $this->api = fn () => $this->withToken(auth('api')->tokenById($superAdmin->id));
    $this->ruta = "/api/v1/plataforma/condominios/{$this->condominio->id}/administradores/{$this->admin->id}/invitacion";
});

it('reenvía la invitación y anula el enlace anterior', function () {
    $anterior = app(InvitacionService::class)->crear($this->admin, $this->condominio);

    ($this->api)()->postJson($this->ruta)
        ->assertOk()
        ->assertJsonPath('data.email', 'maria@losarupos.ec')
        ->assertJsonPath('data.estado', 'invitado');

    Mail::assertQueued(InvitacionAdministradorMail::class, fn ($m) => $m->hasTo('maria@losarupos.ec'));
    $this->getJson("/api/v1/auth/invitaciones/{$anterior}")->assertNotFound();
    expect(Invitacion::query()->where('user_id', $this->admin->id)->whereNull('aceptada_en')->where('expira_en', '>', now())->count())->toBe(1);
});

it('corrige el correo antes de reenviar', function () {
    ($this->api)()->postJson($this->ruta, ['email' => ' Maria.Rivas@Correo.EC '])
        ->assertOk()
        ->assertJsonPath('data.email', 'maria.rivas@correo.ec');

    expect($this->admin->fresh()->email)->toBe('maria.rivas@correo.ec');
    Mail::assertQueued(InvitacionAdministradorMail::class, fn ($m) => $m->hasTo('maria.rivas@correo.ec'));
});

it('no usa un correo de otra cuenta', function () {
    User::factory()->create(['email' => 'ocupado@correo.ec']);

    ($this->api)()->postJson($this->ruta, ['email' => 'ocupado@correo.ec'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.email.0', 'Ese correo ya pertenece a otra cuenta.');
    Mail::assertNothingQueued();
});

it('no reenvía a quien ya creó su contraseña', function () {
    $this->admin->forceFill(['activo' => true])->save();

    ($this->api)()->postJson($this->ruta)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ADMINISTRADOR_ACTIVO');
    Mail::assertNothingQueued();
});

it('responde 404 si la persona no es administradora de ese condominio', function () {
    $otro = Condominio::factory()->create();

    ($this->api)()->postJson("/api/v1/plataforma/condominios/{$otro->id}/administradores/{$this->admin->id}/invitacion")
        ->assertNotFound();
    Mail::assertNothingQueued();
});

it('exige el permiso de plataforma', function () {
    [, $token] = usuarioConToken($this->condominio);

    $this->withToken($token)->postJson($this->ruta)->assertForbidden();
});
