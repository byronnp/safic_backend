<?php

use App\Core\Auth\Models\Invitacion;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Membresia;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Usuarios\Actions\ActualizarUsuarioAction;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use Illuminate\Support\Facades\Mail;

function rolesEn(Condominio $condominio, User $user): array
{
    $anterior = getPermissionsTeamId();
    setPermissionsTeamId($condominio->id);
    $user->unsetRelation('roles');
    $roles = $user->getRoleNames()->sort()->values()->all();
    setPermissionsTeamId($anterior);
    $user->unsetRelation('roles');

    return $roles;
}

beforeEach(function () {
    Mail::fake();

    $plan = Plan::create(['codigo' => 'profesional', 'nombre' => 'Profesional', 'limite_administrativos' => 3, 'valor_unidad_sugerido' => '2.00', 'orden' => 2, 'activo' => true]);
    $this->condominio = Condominio::factory()->create(['plan_id' => $plan->id]);
    [$this->admin, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $this->datos = fn (array $cambios = []) => $cambios + [
        'nombre' => 'Carlos Mera', 'cedula' => '1710000017', 'email' => 'carlos@example.com', 'rol' => 'guardia',
    ];
    $this->invitar = fn (array $cambios = []) => ($this->api)()->postJson('/api/v1/usuarios', ($this->datos)($cambios));
    $this->manana = now()->addMonths(6)->toDateString();
});

it('lista al equipo con su perfil y el uso del cupo del plan', function () {
    $guardia = User::factory()->miembroDe($this->condominio, Rol::Guardia, false)->create(['name' => 'Carlos Mera']);

    $r = ($this->api)()->getJson('/api/v1/usuarios')->assertOk()
        ->assertJsonPath('meta.cupo', ['plan' => 'Profesional', 'limite' => 3, 'usados' => 1]);

    $porNombre = collect($r->json('data'))->keyBy('nombre');
    expect($r->json('data'))->toHaveCount(2)
        ->and($porNombre[$this->admin->name])->toMatchArray(['perfil' => 'administrador', 'cuenta_cupo' => true, 'estado' => 'activo', 'es_yo' => true])
        ->and($porNombre['Carlos Mera'])->toMatchArray(['id' => $guardia->id, 'perfil' => 'guardia', 'cuenta_cupo' => false, 'es_yo' => false]);
});

it('invita a un guardia: cuenta inactiva, perfil, invitación y correo', function () {
    ($this->invitar)()->assertCreated()
        ->assertJsonPath('message', 'Invitación enviada.')
        ->assertJsonPath('data.invitacion_enviada', true)
        ->assertJsonPath('data.perfil', 'guardia')
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.cuenta_cupo', false);

    $user = User::where('email', 'carlos@example.com')->sole();
    expect($user->activo)->toBeFalse()
        ->and(rolesEn($this->condominio, $user))->toBe(['guardia'])
        ->and(Invitacion::where('user_id', $user->id)->where('condominio_id', $this->condominio->id)->count())->toBe(1);
    Mail::assertQueued(InvitacionUsuarioMail::class, fn ($m) => $m->hasTo('carlos@example.com') && $m->perfil === 'Guardia');
});

it('el contador necesita fecha de vencimiento y consume cupo', function () {
    ($this->invitar)(['rol' => 'contador'])->assertStatus(422)
        ->assertJsonPath('error.fields.acceso_hasta.0', 'El contador necesita una fecha de vencimiento del acceso.');
    ($this->invitar)(['rol' => 'contador', 'acceso_hasta' => now()->subDay()->toDateString()])->assertStatus(422);

    ($this->invitar)(['rol' => 'contador', 'acceso_hasta' => $this->manana])->assertCreated()
        ->assertJsonPath('data.cuenta_cupo', true)->assertJsonPath('data.acceso_hasta', $this->manana);
    ($this->api)()->getJson('/api/v1/usuarios')->assertJsonPath('meta.cupo.usados', 2);
});

it('respeta el límite de usuarios administrativos del plan', function () {
    $this->condominio->plan()->update(['limite_administrativos' => 2]);

    ($this->invitar)(['rol' => 'contador', 'acceso_hasta' => $this->manana])->assertCreated();
    ($this->invitar)(['rol' => 'administrador', 'email' => 'otro@example.com', 'cedula' => '1710000025'])
        ->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_USUARIOS')
        ->assertJsonPath('error.details', ['limite' => 2, 'usados' => 2]);

    // Guardia y mantenimiento no cuentan
    ($this->invitar)(['rol' => 'mantenimiento', 'email' => 'pedro@example.com', 'cedula' => '1710000033'])->assertCreated();
    expect(User::where('email', 'otro@example.com')->exists())->toBeFalse();
});

it('una persona que ya tiene cuenta se suma sin invitación', function () {
    $otro = Condominio::factory()->create();
    $existente = User::factory()->miembroDe($otro, Rol::Guardia)->create(['email' => 'carlos@example.com', 'cedula' => '1710000017', 'activo' => true]);

    ($this->invitar)()->assertCreated()
        ->assertJsonPath('message', 'Persona agregada al equipo.')
        ->assertJsonPath('data.invitacion_enviada', false);

    expect(Membresia::where('user_id', $existente->id)->where('condominio_id', $this->condominio->id)->exists())->toBeTrue();
    Mail::assertNothingQueued();
});

it('no repite a quien ya está en el equipo ni usa una cédula ajena', function () {
    ($this->invitar)()->assertCreated();

    ($this->invitar)()->assertStatus(409)->assertJsonPath('error.code', 'USUARIO_YA_EXISTE');
    ($this->invitar)(['email' => 'distinto@example.com'])->assertStatus(422)->assertJsonPath('error.code', 'CEDULA_EN_USO');
});

it('valida los datos de la invitación', function () {
    $r = ($this->invitar)(['nombre' => '', 'cedula' => '1712345678', 'email' => 'no-es-correo', 'celular' => '123', 'rol' => 'presidente'])->assertStatus(422);

    expect(array_keys($r->json('error.fields')))->toEqualCanonicalizing(['nombre', 'cedula', 'email', 'celular', 'rol']);
    ($this->invitar)(['rol' => 'super_admin'])->assertStatus(422)->assertJsonPath('error.fields.rol.0', fn ($m) => str_contains($m, 'Elige administrador'));
});

it('cambia el perfil sin perder el cargo de directiva', function () {
    $user = User::factory()->miembroDe($this->condominio, Rol::Guardia, false)->create();
    $anterior = getPermissionsTeamId();
    setPermissionsTeamId($this->condominio->id);
    $user->assignRole(Rol::Presidente->value);
    setPermissionsTeamId($anterior);

    ($this->api)()->patchJson("/api/v1/usuarios/{$user->id}", ['rol' => 'mantenimiento'])->assertOk()
        ->assertJsonPath('data.perfil', 'mantenimiento')
        ->assertJsonPath('message', 'Cambios guardados.');

    expect(rolesEn($this->condominio, $user->fresh()))->toBe(['mantenimiento', 'presidente']);
});

it('pasar a un perfil que consume cupo respeta el plan y pide vigencia al contador', function () {
    $this->condominio->plan()->update(['limite_administrativos' => 1]);
    $user = User::factory()->miembroDe($this->condominio, Rol::Guardia, false)->create();

    ($this->api)()->patchJson("/api/v1/usuarios/{$user->id}", ['rol' => 'administrador'])
        ->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_USUARIOS');
    expect(rolesEn($this->condominio, $user))->toBe(['guardia']);

    $this->condominio->plan()->update(['limite_administrativos' => 2]);
    ($this->api)()->patchJson("/api/v1/usuarios/{$user->id}", ['rol' => 'contador'])
        ->assertStatus(422)->assertJsonPath('error.fields.acceso_hasta.0', 'El contador necesita una fecha de vencimiento del acceso.');
    ($this->api)()->patchJson("/api/v1/usuarios/{$user->id}", ['rol' => 'contador', 'acceso_hasta' => $this->manana])
        ->assertOk()->assertJsonPath('data.cuenta_cupo', true);
});

it('desactiva y reactiva: desactivar libera el cupo y reactivar lo vuelve a pedir', function () {
    $this->condominio->plan()->update(['limite_administrativos' => 2]);
    $contador = User::factory()->miembroDe($this->condominio, Rol::Contador, false)->create();
    Membresia::where('user_id', $contador->id)->update(['acceso_hasta' => $this->manana]);

    ($this->api)()->patchJson("/api/v1/usuarios/{$contador->id}", ['activo' => false])->assertOk()
        ->assertJsonPath('data.estado', 'desactivado')->assertJsonPath('data.cuenta_cupo', false);
    ($this->api)()->getJson('/api/v1/usuarios')->assertJsonPath('meta.cupo.usados', 1);

    // Otro administrador ocupa el lugar liberado
    User::factory()->miembroDe($this->condominio, Rol::Administrador, false)->create();
    ($this->api)()->patchJson("/api/v1/usuarios/{$contador->id}", ['activo' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_USUARIOS');
});

it('nadie cambia su propio acceso', function () {
    ($this->api)()->patchJson("/api/v1/usuarios/{$this->admin->id}", ['activo' => false])
        ->assertStatus(409)->assertJsonPath('error.code', 'USUARIO_PROPIO');
    ($this->api)()->patchJson("/api/v1/usuarios/{$this->admin->id}", ['rol' => 'guardia'])
        ->assertStatus(409)->assertJsonPath('error.code', 'USUARIO_PROPIO');

    expect(rolesEn($this->condominio, $this->admin))->toBe(['administrador']);
});

it('el condominio no se queda sin administrador', function () {
    $unico = User::factory()->miembroDe($this->condominio, Rol::Administrador, false)->create();
    Membresia::where('user_id', $this->admin->id)->update(['activo' => false]);
    $actor = User::factory()->create();

    foreach ([['activo' => false], ['rol' => 'guardia']] as $cambio) {
        expect(fn () => enCondominio($this->condominio, fn () => app(ActualizarUsuarioAction::class)->execute($unico->id, $cambio, $actor)))
            ->toThrow(fn (ApiException $e) => $e->errorCode === 'ULTIMO_ADMINISTRADOR');
    }

    // Con otro administrador vigente sí se puede
    Membresia::where('user_id', $this->admin->id)->update(['activo' => true]);
    enCondominio($this->condominio, fn () => app(ActualizarUsuarioAction::class)->execute($unico->id, ['activo' => false], $actor));
    expect(Membresia::where('user_id', $unico->id)->value('activo'))->toBeFalse();
});

it('no se ve ni se toca a personas de otro condominio', function () {
    $otro = Condominio::factory()->create();
    $ajeno = User::factory()->miembroDe($otro, Rol::Guardia)->create();

    expect(collect(($this->api)()->getJson('/api/v1/usuarios')->json('data'))->pluck('id'))->not->toContain($ajeno->id);
    ($this->api)()->patchJson("/api/v1/usuarios/{$ajeno->id}", ['activo' => false])->assertNotFound();
    ($this->api)()->postJson("/api/v1/usuarios/{$ajeno->id}/invitacion")->assertNotFound();
    expect(Membresia::where('user_id', $ajeno->id)->value('activo'))->toBeTrue();
});

it('reenvía la invitación a quien no creó su contraseña y anula el enlace anterior', function () {
    ($this->invitar)()->assertCreated();
    $user = User::where('email', 'carlos@example.com')->sole();
    $primera = Invitacion::where('user_id', $user->id)->sole();

    ($this->api)()->postJson("/api/v1/usuarios/{$user->id}/invitacion")->assertOk()->assertJsonPath('message', 'Invitación reenviada.');

    expect(Invitacion::where('user_id', $user->id)->count())->toBe(2)
        ->and($primera->fresh()->vigente())->toBeFalse();
    Mail::assertQueued(InvitacionUsuarioMail::class, 2);

    $user->forceFill(['activo' => true])->save();
    ($this->api)()->postJson("/api/v1/usuarios/{$user->id}/invitacion")->assertStatus(409)->assertJsonPath('error.code', 'USUARIO_ACTIVO');
});

it('solo quien gestiona usuarios entra', function () {
    foreach ([Rol::Guardia, Rol::Residente, Rol::Contador] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/usuarios')->assertForbidden();
        $api()->postJson('/api/v1/usuarios', ($this->datos)())->assertForbidden();
        $api()->patchJson("/api/v1/usuarios/{$this->admin->id}", ['activo' => false])->assertForbidden();
        $api()->postJson("/api/v1/usuarios/{$this->admin->id}/invitacion")->assertForbidden();
    }
});

it('sin plan no hay límite de usuarios administrativos', function () {
    $this->condominio->update(['plan_id' => null]);

    foreach (['uno', 'dos', 'tres'] as $i => $n) {
        ($this->invitar)(['rol' => 'administrador', 'email' => "$n@example.com", 'cedula' => ['1710000017', '1710000025', '1710000033'][$i]])->assertCreated();
    }
    ($this->api)()->getJson('/api/v1/usuarios')->assertJsonPath('meta.cupo', ['plan' => null, 'limite' => null, 'usados' => 4]);
});
