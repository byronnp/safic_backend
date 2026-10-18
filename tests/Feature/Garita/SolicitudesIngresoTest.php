<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\SolicitudIngreso;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [$this->userA, $tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->resA = ($this->como)($tokenA);
    $this->resB = ($this->como)($tokenB);
    $this->guardia = ($this->como)($tokenG);
    $this->tesorero = ($this->como)($tokenT);

    $this->pedir = fn (array $extra = []) => ($this->guardia)()->postJson('/api/v1/garita/solicitudes', $extra + [
        'unidad_codigo' => 'a-102', 'nombre' => 'Carlos Andrade', 'tipo' => 'visita', 'placa' => 'pdq-3321',
    ]);
});

it('el guardia le pregunta al residente y este ve quién espera y cuánto tiempo le queda', function () {
    $r = ($this->pedir)()->assertCreated();

    $lista = ($this->resA)()->getJson('/api/v1/mis-solicitudes-ingreso')->assertOk()->json('data');
    expect($lista)->toHaveCount(1)->and($lista[0])->toMatchArray(['id' => $r->json('data.id'), 'nombre' => 'Carlos Andrade', 'tipo' => 'visita', 'placa' => 'PDQ-3321', 'unidad' => 'A-102'])
        ->and($lista[0]['segundos_restantes'])->toBeGreaterThan(150)->toBeLessThanOrEqual(180);
    expect(($this->resB)()->getJson('/api/v1/mis-solicitudes-ingreso')->json('data'))->toBe([]);
});

it('permitir: el guardia lo ve y registra el ingreso autorizado por la app del residente', function () {
    $id = ($this->pedir)()->json('data.id');

    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertOk()->assertJsonPath('data.estado', 'permitida');

    $panel = ($this->guardia)()->getJson('/api/v1/garita/panel')->json('data.solicitudes');
    expect($panel)->toHaveCount(1)->and($panel[0])->toMatchArray(['estado' => 'permitida', 'puede_ingresar' => true, 'nombre' => 'Carlos Andrade']);

    ($this->guardia)()->postJson("/api/v1/garita/solicitudes/{$id}/ingreso")->assertCreated();
    $a = enCondominio($this->condominio, fn () => AccesoGarita::first());
    expect($a->autorizado_por)->toBe('app_residente')->and($a->nombre)->toBe('Carlos Andrade')->and($a->unidad_id)->toBe($this->unidadA->id)
        ->and(enCondominio($this->condominio, fn () => SolicitudIngreso::find($id)->estado))->toBe('usada');
    expect(($this->guardia)()->getJson('/api/v1/garita/panel')->json('data.solicitudes'))->toBe([]);
    ($this->guardia)()->postJson("/api/v1/garita/solicitudes/{$id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_NO_PERMITIDA');
});

it('rechazar: no se puede registrar el ingreso y el guardia lo ve', function () {
    $id = ($this->pedir)()->json('data.id');

    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => false])->assertOk()->assertJsonPath('data.estado', 'rechazada');

    expect(($this->guardia)()->getJson('/api/v1/garita/panel')->json('data.solicitudes.0'))->toMatchArray(['estado' => 'rechazada', 'puede_ingresar' => false]);
    ($this->guardia)()->postJson("/api/v1/garita/solicitudes/{$id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_NO_PERMITIDA');
    expect(enCondominio($this->condominio, fn () => AccesoGarita::count()))->toBe(0);
});

it('gana la primera respuesta de la unidad', function () {
    $id = ($this->pedir)()->json('data.id');
    // Otra persona de la misma unidad con cuenta
    $otra = enCondominio($this->condominio, function () {
        $user = User::factory()->miembroDe($this->condominio, Rol::Residente)->create();
        $p = Persona::factory()->create();
        $p->forceFill(['user_id' => $user->id])->save();
        Ocupante::create(['unidad_id' => $this->unidadA->id, 'persona_id' => $p->id, 'relacion' => 'residente', 'es_principal' => false, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);

        return $user;
    });
    $segunda = ($this->como)(auth('api')->tokenById($otra->id));

    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertOk();
    $segunda()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => false])->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_YA_RESPONDIDA');
    expect(enCondominio($this->condominio, fn () => SolicitudIngreso::find($id)->estado))->toBe('permitida');
});

it('si pasan los 3 minutos, vence: el residente ya no responde y el guardia lo ve vencida', function () {
    $id = ($this->pedir)()->json('data.id');
    enCondominio($this->condominio, fn () => SolicitudIngreso::whereKey($id)->update(['expira_en' => now()->subSecond()]));

    expect(($this->resA)()->getJson('/api/v1/mis-solicitudes-ingreso')->json('data'))->toBe([]);
    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_VENCIDA');
    expect(($this->guardia)()->getJson('/api/v1/garita/panel')->json('data.solicitudes.0.estado'))->toBe('vencida');
});

it('el permiso del residente vale 15 minutos', function () {
    $id = ($this->pedir)()->json('data.id');
    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertOk();
    enCondominio($this->condominio, fn () => SolicitudIngreso::whereKey($id)->update(['respondida_en' => now()->subMinutes(16)]));

    ($this->guardia)()->postJson("/api/v1/garita/solicitudes/{$id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_VENCIDA');
    expect(($this->guardia)()->getJson('/api/v1/garita/panel')->json('data.solicitudes.0.puede_ingresar'))->toBeFalse();
});

it('no pregunta a una unidad nadie de la cual use la app, ni repite la misma pregunta abierta', function () {
    $vacia = enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'C-5']));
    $sinCuenta = enCondominio($this->condominio, function () use ($vacia) {
        $p = Persona::factory()->create();
        Ocupante::create(['unidad_id' => $vacia->id, 'persona_id' => $p->id, 'relacion' => 'propietario', 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);
    });

    ($this->pedir)(['unidad_codigo' => 'C-5'])->assertStatus(422)->assertJsonPath('error.code', 'SIN_RESIDENTES_CON_CUENTA');
    ($this->pedir)(['unidad_codigo' => 'ZZ-9'])->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_NO_ENCONTRADA');
    ($this->pedir)()->assertCreated();
    ($this->pedir)()->assertStatus(409)->assertJsonPath('error.code', 'SOLICITUD_ABIERTA');
    ($this->pedir)(['nombre' => 'Otra persona'])->assertCreated();
    ($this->pedir)(['nombre' => 'Ab'])->assertStatus(422);
    ($this->pedir)(['tipo' => 'otro'])->assertStatus(422);
});

it('el guardia puede retirar su pregunta', function () {
    $id = ($this->pedir)()->json('data.id');

    ($this->guardia)()->postJson("/api/v1/garita/solicitudes/{$id}/cancelar")->assertOk();

    expect(($this->resA)()->getJson('/api/v1/mis-solicitudes-ingreso')->json('data'))->toBe([]);
    ($this->resA)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertStatus(409);
});

it('un residente no ve ni responde las solicitudes de otra unidad, y los permisos se respetan', function () {
    $id = ($this->pedir)()->json('data.id');

    ($this->resB)()->postJson("/api/v1/mis-solicitudes-ingreso/{$id}/responder", ['permitir' => true])->assertNotFound();
    ($this->resA)()->postJson('/api/v1/garita/solicitudes', [])->assertForbidden();
    ($this->tesorero)()->getJson('/api/v1/mis-solicitudes-ingreso')->assertForbidden();
    ($this->guardia)()->getJson('/api/v1/mis-solicitudes-ingreso')->assertForbidden();
    ($this->resA)()->postJson("/api/v1/garita/solicitudes/{$id}/ingreso")->assertForbidden();
});
