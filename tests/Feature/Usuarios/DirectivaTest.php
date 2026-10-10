<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use App\Modules\Usuarios\Models\CargoDirectiva;
use Illuminate\Support\Facades\Mail;

function propietaria(Condominio $condominio, string $unidad, array $persona = [], string $relacion = 'propietario'): Persona
{
    return enCondominio($condominio, function () use ($unidad, $persona, $relacion) {
        $u = Unidad::factory()->create(['codigo' => $unidad]);
        $p = Persona::factory()->create($persona);
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => $p->id, 'relacion' => $relacion, 'es_principal' => false, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);

        return $p;
    });
}

function rolesDe(Condominio $condominio, User $user): array
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

    $this->fernando = propietaria($this->condominio, 'C-12', ['nombres' => 'Fernando', 'apellidos' => 'Salazar', 'email' => 'fsalazar@example.com']);
    $this->paola = propietaria($this->condominio, 'B-201', ['nombres' => 'Paola', 'apellidos' => 'Cedeño', 'email' => 'pcedeno@example.com']);

    $this->hasta = hoyLocal()->addYear()->toDateString();
    $this->asignar = fn (string $cargo, Persona $persona, array $cambios = []) => ($this->api)()->postJson("/api/v1/directiva/{$cargo}", $cambios + [
        'persona_id' => $persona->id, 'acta' => 'Acta 2026-01', 'periodo_hasta' => $this->hasta,
    ]);
});

it('sin nombramientos los cuatro cargos están vacantes', function () {
    $r = ($this->api)()->getJson('/api/v1/directiva')->assertOk();

    expect(collect($r->json('data'))->pluck('cargo')->all())->toBe(['presidente', 'vicepresidente', 'secretario', 'tesorero'])
        ->and(collect($r->json('data'))->pluck('estado')->unique()->all())->toBe(['vacante'])
        ->and($r->json('data.0.titular'))->toBeNull();
});

it('nombra a un propietario: cuenta inactiva, cargo, invitación y periodo', function () {
    ($this->asignar)('presidente', $this->fernando)->assertOk()
        ->assertJsonPath('message', 'Cargo asignado.')
        ->assertJsonPath('data.estado', 'vigente')
        ->assertJsonPath('data.titular.nombre', 'Fernando Salazar')
        ->assertJsonPath('data.titular.unidad', 'C-12')
        ->assertJsonPath('data.titular.sigue_siendo_propietario', true)
        ->assertJsonPath('data.acta', 'Acta 2026-01')
        ->assertJsonPath('data.periodo_fin', $this->hasta);

    $user = User::where('email', 'fsalazar@example.com')->sole();
    expect($user->activo)->toBeFalse()
        ->and(rolesDe($this->condominio, $user))->toBe(['presidente']);
    Mail::assertQueued(InvitacionUsuarioMail::class, fn ($m) => $m->hasTo('fsalazar@example.com') && $m->perfil === 'Presidente');

    ($this->api)()->getJson('/api/v1/directiva')->assertJsonPath('data.0.titular.persona_id', $this->fernando->id);
});

it('una persona que ya tiene cuenta activa recibe el cargo sin nueva invitación', function () {
    $existente = User::factory()->miembroDe($this->condominio, Rol::Residente, false)->create(['email' => 'pcedeno@example.com', 'activo' => true]);

    ($this->asignar)('vicepresidente', $this->paola)->assertOk();

    expect(rolesDe($this->condominio, $existente))->toBe(['residente', 'vicepresidente'])
        ->and(User::where('email', 'pcedeno@example.com')->count())->toBe(1);
    Mail::assertNothingQueued();
});

it('los candidatos son propietarios, con el motivo si no están disponibles', function () {
    propietaria($this->condominio, 'A-1', ['nombres' => 'Sin', 'apellidos' => 'Correo', 'email' => null]);
    propietaria($this->condominio, 'A-2', ['nombres' => 'Inquilina', 'apellidos' => 'Soto'], 'inquilino');
    ($this->asignar)('presidente', $this->fernando)->assertOk();

    $candidatos = collect(($this->api)()->getJson('/api/v1/directiva/secretario/candidatos')->assertOk()->json('data'))->keyBy('nombre');

    expect($candidatos->keys()->sort()->values()->all())->toBe(['Fernando Salazar', 'Paola Cedeño', 'Sin Correo'])
        ->and($candidatos['Paola Cedeño'])->toMatchArray(['disponible' => true, 'motivo' => null, 'unidad' => 'B-201'])
        ->and($candidatos['Fernando Salazar'])->toMatchArray(['disponible' => false, 'motivo' => 'ocupa_cargo', 'cargo_actual' => 'presidente'])
        ->and($candidatos['Sin Correo'])->toMatchArray(['disponible' => false, 'motivo' => 'sin_correo']);

    // Para su propio cargo, el titular no aparece
    $propios = collect(($this->api)()->getJson('/api/v1/directiva/presidente/candidatos')->json('data'))->pluck('nombre');
    expect($propios)->not->toContain('Fernando Salazar');
});

it('solo los propietarios con correo pueden ocupar un cargo', function () {
    $inquilina = propietaria($this->condominio, 'A-2', ['email' => 'inq@example.com'], 'inquilino');
    $sinCorreo = propietaria($this->condominio, 'A-3', ['email' => null]);

    ($this->asignar)('presidente', $inquilina)->assertStatus(422)->assertJsonPath('error.code', 'NO_ES_PROPIETARIO');
    ($this->asignar)('presidente', $sinCorreo)->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_CORREO');
    expect(CargoDirectiva::count())->toBe(0);
});

it('un cargo una persona y una persona un cargo', function () {
    ($this->asignar)('presidente', $this->fernando)->assertOk();

    ($this->asignar)('secretario', $this->fernando)->assertStatus(409)->assertJsonPath('error.code', 'PERSONA_CON_CARGO')
        ->assertJsonPath('error.message', 'Esa persona ya es Presidente: una persona ocupa un solo cargo.');
    ($this->asignar)('presidente', $this->fernando)->assertStatus(409)->assertJsonPath('error.code', 'YA_ES_TITULAR');
    expect(enCondominio($this->condominio, fn () => CargoDirectiva::whereNull('cerrado_en')->count()))->toBe(1);
});

it('cambiar de titular cierra el periodo anterior y le quita solo ese cargo', function () {
    $anterior = User::factory()->miembroDe($this->condominio, Rol::Residente, false)->create(['email' => 'fsalazar@example.com', 'activo' => true]);
    ($this->asignar)('presidente', $this->fernando)->assertOk();
    expect(rolesDe($this->condominio, $anterior))->toBe(['presidente', 'residente']);

    ($this->asignar)('presidente', $this->paola, ['acta' => 'Acta 2026-03'])->assertOk()
        ->assertJsonPath('data.titular.nombre', 'Paola Cedeño')->assertJsonPath('data.acta', 'Acta 2026-03');

    expect(rolesDe($this->condominio, $anterior))->toBe(['residente'])
        ->and(rolesDe($this->condominio, User::where('email', 'pcedeno@example.com')->sole()))->toBe(['presidente']);

    $historial = enCondominio($this->condominio, fn () => CargoDirectiva::where('cargo', 'presidente')->orderBy('id')->get());
    expect($historial)->toHaveCount(2)
        ->and($historial[0]->cerrado_en)->not->toBeNull()
        ->and($historial[1]->cerrado_en)->toBeNull();

    // Quien dejó el cargo puede ocupar otro
    ($this->asignar)('tesorero', $this->fernando)->assertOk();
});

it('el cargo con el periodo vencido sigue como prorrogado', function () {
    ($this->asignar)('secretario', $this->paola)->assertOk();
    enCondominio($this->condominio, fn () => CargoDirectiva::where('cargo', 'secretario')->update(['periodo_fin' => hoyLocal()->subDay()->toDateString()]));

    expect(collect(($this->api)()->getJson('/api/v1/directiva')->json('data'))->firstWhere('cargo', 'secretario')['estado'])->toBe('prorrogado');
});

it('avisa si el titular ya no es propietario', function () {
    ($this->asignar)('presidente', $this->fernando)->assertOk();
    enCondominio($this->condominio, fn () => Ocupante::where('persona_id', $this->fernando->id)->update(['fecha_fin' => hoyLocal()->subDay()->toDateString()]));

    $presidente = collect(($this->api)()->getJson('/api/v1/directiva')->json('data'))->firstWhere('cargo', 'presidente');
    expect($presidente['titular']['sigue_siendo_propietario'])->toBeFalse()->and($presidente['estado'])->toBe('vigente');
});

it('el tesorero consume cupo del plan y el relevo no pide uno más', function () {
    $this->condominio->plan()->update(['limite_administrativos' => 1]);

    ($this->asignar)('tesorero', $this->fernando)->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_USUARIOS');
    // Todo o nada: ni la cuenta ni el cargo quedan a medias
    expect(User::where('email', 'fsalazar@example.com')->exists())->toBeFalse()
        ->and(enCondominio($this->condominio, fn () => CargoDirectiva::count()))->toBe(0);

    $this->condominio->plan()->update(['limite_administrativos' => 2]);
    ($this->asignar)('tesorero', $this->fernando)->assertOk();
    ($this->asignar)('tesorero', $this->paola)->assertOk(); // el anterior libera su lugar
    ($this->api)()->getJson('/api/v1/usuarios')->assertJsonPath('meta.cupo.usados', 2);

    // Los demás cargos no consumen cupo
    $this->condominio->plan()->update(['limite_administrativos' => 2]);
    $otra = propietaria($this->condominio, 'D-4', ['email' => 'otra@example.com']);
    ($this->asignar)('presidente', $otra)->assertOk();
});

it('valida el acta, el periodo y la persona', function () {
    ($this->asignar)('presidente', $this->fernando, ['acta' => ''])->assertStatus(422)->assertJsonPath('error.fields.acta.0', 'Escribe el acta que respalda el nombramiento.');
    ($this->asignar)('presidente', $this->fernando, ['periodo_hasta' => hoyLocal()->toDateString()])->assertStatus(422)->assertJsonPath('error.fields.periodo_hasta.0', 'El periodo debe terminar después de hoy.');
    ($this->asignar)('presidente', $this->fernando, ['periodo_hasta' => hoyLocal()->addYears(5)->toDateString()])->assertStatus(422)->assertJsonPath('error.fields.periodo_hasta.0', 'El periodo puede durar hasta 4 años.');
    ($this->asignar)('presidente', $this->fernando, ['persona_id' => 999999])->assertStatus(422)->assertJsonPath('error.fields.persona_id.0', 'Esa persona no existe en este condominio.');
    expect(CargoDirectiva::withoutGlobalScopes()->count())->toBe(0);
});

it('solo existen los cuatro cargos de la directiva', function () {
    ($this->api)()->postJson('/api/v1/directiva/administrador', ['persona_id' => $this->fernando->id, 'acta' => 'x', 'periodo_hasta' => $this->hasta])->assertNotFound();
    ($this->api)()->getJson('/api/v1/directiva/guardia/candidatos')->assertNotFound();
});

it('no mezcla personas ni cargos de otro condominio', function () {
    $otro = Condominio::factory()->create();
    $ajena = propietaria($otro, 'Z-1', ['email' => 'ajena@example.com']);
    enCondominio($otro, fn () => CargoDirectiva::create(['cargo' => 'presidente', 'persona_id' => $ajena->id, 'periodo_inicio' => hoyLocal()->subYear()->toDateString(), 'periodo_fin' => $this->hasta, 'acta' => 'Acta ajena']));

    ($this->asignar)('presidente', $ajena)->assertStatus(422)->assertJsonPath('error.fields.persona_id.0', 'Esa persona no existe en este condominio.');
    expect(collect(($this->api)()->getJson('/api/v1/directiva')->json('data'))->pluck('estado')->unique()->all())->toBe(['vacante']);
    expect(collect(($this->api)()->getJson('/api/v1/directiva/presidente/candidatos')->json('data'))->pluck('nombre'))->not->toContain($ajena->nombreCompleto());
});

it('solo quien gestiona usuarios ve y cambia la directiva', function () {
    foreach ([Rol::Guardia, Rol::Residente, Rol::Presidente] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/directiva')->assertForbidden();
        $api()->getJson('/api/v1/directiva/presidente/candidatos')->assertForbidden();
        $api()->postJson('/api/v1/directiva/presidente', ['persona_id' => $this->fernando->id, 'acta' => 'x', 'periodo_hasta' => $this->hasta])->assertForbidden();
    }
});
