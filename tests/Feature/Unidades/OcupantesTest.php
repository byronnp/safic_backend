<?php

use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    [$this->unidad, $this->lucia, $this->diego] = enCondominio($this->condominio, fn () => [
        Unidad::factory()->create(['codigo' => 'A-102']),
        Persona::factory()->create(['nombres' => 'Lucía', 'apellidos' => 'Paredes']),
        Persona::factory()->create(['nombres' => 'Diego', 'apellidos' => 'Mora']),
    ]);
    $this->hace = hoyLocal()->subYear()->toDateString();
    $this->asignar = fn (array $datos) => ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/ocupantes", $datos + ['fecha_inicio' => $this->hace]);
});

it('una unidad sin ocupantes está vacía', function () {
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")
        ->assertJsonPath('data.estado', 'vacia')
        ->assertJsonPath('data.ocupantes', [])
        ->assertJsonPath('data.propietarios', [])
        ->assertJsonPath('data.ocupante_principal', null);
});

it('un propietario que no reside no la ocupa; si es principal, sí', function () {
    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'propietario'])->assertCreated();
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")
        ->assertJsonPath('data.estado', 'vacia')
        ->assertJsonPath('data.propietarios', ['Lucía Paredes']);

    ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'residente', 'es_principal' => true])->assertCreated();
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")
        ->assertJsonPath('data.estado', 'ocupada')
        ->assertJsonPath('data.ocupante_principal', ['nombre' => 'Diego Mora', 'relacion' => 'residente'])
        ->assertJsonPath('data.ocupantes.0.persona.nombres', 'Diego');
});

it('con un inquilino vigente está arrendada', function () {
    ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'inquilino', 'es_principal' => true])
        ->assertCreated()
        ->assertJsonPath('data.vigente', true);

    ($this->api)()->getJson('/api/v1/unidades?estado=arrendada')->assertJsonCount(1, 'data');
    ($this->api)()->getJson('/api/v1/unidades?estado=vacia')->assertJsonCount(0, 'data');
    ($this->api)()->getJson('/api/v1/unidades?buscar=mora')->assertJsonPath('data.0.codigo', 'A-102');
});

it('permite un solo ocupante principal por periodo', function () {
    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'propietario', 'es_principal' => true])->assertCreated();

    ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'inquilino', 'es_principal' => true])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PRINCIPAL_OCUPADO');
});

it('no repite la misma relación de una persona en fechas que se cruzan', function () {
    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'propietario'])->assertCreated();

    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'propietario'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'OCUPANTE_DUPLICADO');
});

it('un contacto de emergencia no puede ser principal', function () {
    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'contacto_emergencia', 'es_principal' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('es_principal', 'error.fields');
});

it('finalizar no borra: deja la ocupación en el historial y libera al principal', function () {
    $id = ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'inquilino', 'es_principal' => true])->json('data.id');

    ($this->api)()->patchJson("/api/v1/ocupantes/{$id}/finalizar", ['fecha_fin' => hoyLocal()->subDay()->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.vigente', false);

    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")->assertJsonPath('data.estado', 'vacia');
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}/ocupantes")->assertJsonCount(1, 'data');

    // Ya terminó: no se finaliza otra vez
    ($this->api)()->patchJson("/api/v1/ocupantes/{$id}/finalizar", ['fecha_fin' => hoyLocal()->toDateString()])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'OCUPANTE_FINALIZADO');

    // El nuevo inquilino puede ser principal desde hoy
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/ocupantes", [
        'persona_id' => $this->lucia->id, 'relacion' => 'inquilino', 'es_principal' => true, 'fecha_inicio' => hoyLocal()->toDateString(),
    ])->assertCreated();
});

it('la fecha de fin no puede ser anterior al inicio', function () {
    $id = ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'residente'])->json('data.id');

    ($this->api)()->patchJson("/api/v1/ocupantes/{$id}/finalizar", ['fecha_fin' => hoyLocal()->subYears(2)->toDateString()])
        ->assertUnprocessable();
});

it('un ocupante que empieza en el futuro todavía no ocupa la unidad', function () {
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/ocupantes", [
        'persona_id' => $this->diego->id, 'relacion' => 'inquilino', 'fecha_inicio' => hoyLocal()->addMonth()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.vigente', false);

    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")->assertJsonPath('data.estado', 'vacia');
});

it('el resumen cuenta ocupadas y residentes', function () {
    ($this->asignar)(['persona_id' => $this->lucia->id, 'relacion' => 'propietario'])->assertCreated();
    ($this->asignar)(['persona_id' => $this->diego->id, 'relacion' => 'inquilino', 'es_principal' => true])->assertCreated();
    $otra = enCondominio($this->condominio, fn () => Persona::factory()->create());
    ($this->asignar)(['persona_id' => $otra->id, 'relacion' => 'contacto_emergencia'])->assertCreated();

    ($this->api)()->getJson('/api/v1/unidades/resumen')
        ->assertJsonPath('data.ocupadas', 1)
        ->assertJsonPath('data.residentes', 2);

    expect(enCondominio($this->condominio, fn () => Ocupante::count()))->toBe(3);
});
