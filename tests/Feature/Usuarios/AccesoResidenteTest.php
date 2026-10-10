<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function ocupanteDe(Condominio $condominio, string $codigo, string $relacion, array $persona = [], array $unidad = []): Persona
{
    return enCondominio($condominio, function () use ($codigo, $relacion, $persona, $unidad) {
        $u = Unidad::factory()->create(['codigo' => $codigo] + $unidad);
        $p = Persona::factory()->create($persona);
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => $p->id, 'relacion' => $relacion, 'es_principal' => false, 'fecha_inicio' => now()->subYear()->toDateString()]);

        return $p;
    });
}

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create();
    [$this->admin, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
});

it('da acceso a un propietario: crea su cuenta, la liga a su ficha y le envía la invitación', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['nombres' => 'Ana', 'apellidos' => 'Mora', 'email' => 'ana@example.com']);

    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated()
        ->assertJsonPath('data.invitacion_enviada', true);

    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
    expect($user->activo)->toBeFalse()
        ->and(enCondominio($this->condominio, fn () => $ana->fresh()->user_id))->toBe($user->id)
        ->and($user->membresias()->where('condominio_id', $this->condominio->id)->exists())->toBeTrue();
    Mail::assertQueued(InvitacionUsuarioMail::class, fn ($m) => $m->hasTo('ana@example.com') && $m->perfil === 'Residente');
});

it('el residente queda con su perfil y no gasta cupo de usuarios administrativos', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'inquilino', ['email' => 'ana@example.com']);
    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated();
    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();

    setPermissionsTeamId($this->condominio->id);
    $roles = $user->unsetRelation('roles')->getRoleNames()->all();
    setPermissionsTeamId(null);

    expect($roles)->toBe(['residente']);
});

it('reutiliza la cuenta que ya existe con ese correo y no reenvía invitación si ya está activa', function () {
    User::factory()->create(['email' => 'ana@example.com', 'activo' => true]);
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['email' => 'ana@example.com']);

    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated()
        ->assertJsonPath('data.invitacion_enviada', false);

    Mail::assertNothingQueued();
    expect(User::query()->where('email', 'ana@example.com')->count())->toBe(1);
});

it('no da acceso dos veces, ni sin correo, ni a quien no ocupa una unidad', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['email' => 'ana@example.com']);
    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated();
    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertStatus(409)->assertJsonPath('error.code', 'PERSONA_YA_TIENE_ACCESO');

    $sin = ocupanteDe($this->condominio, 'B-1', 'propietario', ['email' => null]);
    ($this->api)()->postJson("/api/v1/personas/{$sin->id}/acceso")->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_CORREO');

    $suelta = enCondominio($this->condominio, fn () => Persona::factory()->create(['email' => 'sola@example.com']));
    ($this->api)()->postJson("/api/v1/personas/{$suelta->id}/acceso")->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_UNIDAD');

    $contacto = ocupanteDe($this->condominio, 'C-1', 'contacto_emergencia', ['email' => 'c@example.com']);
    ($this->api)()->postJson("/api/v1/personas/{$contacto->id}/acceso")->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_UNIDAD');
});

it('una ocupación terminada ya no da acceso', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'inquilino', ['email' => 'ana@example.com']);
    // Ayer en la hora del condominio (now() es UTC y de noche ya es otro día)
    enCondominio($this->condominio, fn () => Ocupante::query()->where('persona_id', $ana->id)->update(['fecha_fin' => CarbonImmutable::parse(app(Calendario::class)->hoy())->subDay()->toDateString()]));

    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_UNIDAD');
});

it('una persona de otro condominio no es candidata', function () {
    $otro = Condominio::factory()->create();
    $ajena = ocupanteDe($otro, 'Z-1', 'propietario', ['email' => 'ajena@example.com']);

    ($this->api)()->postJson("/api/v1/personas/{$ajena->id}/acceso")->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_UNIDAD');
});

it('solo quien gestiona usuarios da acceso', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['email' => 'ana@example.com']);
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->postJson("/api/v1/personas/{$ana->id}/acceso")->assertForbidden();
});

it('la ficha de la persona indica si ya tiene acceso', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['email' => 'ana@example.com']);

    expect(($this->api)()->getJson("/api/v1/personas/{$ana->id}")->assertOk()->json('data.tiene_acceso'))->toBeFalse();
    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated();
    expect(($this->api)()->getJson("/api/v1/personas/{$ana->id}")->json('data.tiene_acceso'))->toBeTrue();
});

it('las unidades de una cuenta dicen cuáles puede pagar según el responsable de pago', function () {
    $ana = ocupanteDe($this->condominio, 'A-102', 'propietario', ['email' => 'ana@example.com'], ['responsable_pago' => 'propietario']);
    $tambien = enCondominio($this->condominio, function () use ($ana) {
        $u = Unidad::factory()->create(['codigo' => 'B-5', 'responsable_pago' => 'inquilino']);
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => $ana->id, 'relacion' => 'propietario', 'es_principal' => false, 'fecha_inicio' => now()->subYear()->toDateString()]);
    });
    ($this->api)()->postJson("/api/v1/personas/{$ana->id}/acceso")->assertCreated();
    $user = User::query()->where('email', 'ana@example.com')->firstOrFail();

    $unidades = enCondominio($this->condominio, fn () => app(UnidadesDeCuentaAction::class)->execute($user->id));

    expect($unidades)->toHaveCount(2)
        ->and(collect($unidades)->firstWhere('codigo', 'A-102')['puede_pagar'])->toBeTrue()
        ->and(collect($unidades)->firstWhere('codigo', 'B-5')['puede_pagar'])->toBeFalse();
});
