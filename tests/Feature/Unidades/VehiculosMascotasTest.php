<?php

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    $this->unidad = enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'A-102']));
});

it('registra un vehículo con la placa normalizada y aparece en el detalle', function () {
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", [
        'placa' => 'pba 1234', 'tipo' => 'auto', 'marca' => 'Kia', 'modelo' => 'Rio', 'color' => 'Gris',
    ])->assertCreated()->assertJsonPath('data.placa', 'PBA-1234');

    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")
        ->assertJsonPath('data.vehiculos.0.placa', 'PBA-1234')
        ->assertJsonPath('data.vehiculos.0.marca', 'Kia');
});

it('valida la placa y no la repite en el condominio', function () {
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'PB-12', 'tipo' => 'auto'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.placa.0', 'La placa no es válida (ej. PBA-1234 o una moto IA-123B).');

    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'PBA-1234', 'tipo' => 'auto'])->assertCreated();
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'pba1234', 'tipo' => 'moto'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.placa.0', 'Esa placa ya está registrada en el condominio.');
});

it('al quitar un vehículo su placa queda libre', function () {
    $id = ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'IA-123B', 'tipo' => 'moto'])->json('data.id');

    ($this->api)()->deleteJson("/api/v1/vehiculos/{$id}")->assertNoContent();
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'IA-123B', 'tipo' => 'moto'])->assertCreated();
});

it('edita un vehículo sin chocar con su propia placa', function () {
    $id = ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'PBA-1234', 'tipo' => 'auto'])->json('data.id');

    ($this->api)()->patchJson("/api/v1/vehiculos/{$id}", ['placa' => 'PBA-1234', 'color' => 'Rojo'])
        ->assertOk()
        ->assertJsonPath('data.color', 'Rojo');
});

it('busca unidades por placa con o sin guion', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'B-201']));
    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'PBA-1234', 'tipo' => 'auto'])->assertCreated();

    ($this->api)()->getJson('/api/v1/unidades?buscar=pba12')->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', 'A-102');
    ($this->api)()->getJson('/api/v1/unidades?buscar=PBA-1234')->assertJsonCount(1, 'data');
});

it('registra, edita y quita mascotas', function () {
    $id = ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/mascotas", ['nombre' => 'Luna', 'especie' => 'perro', 'raza' => 'Mestiza'])
        ->assertCreated()->json('data.id');

    ($this->api)()->patchJson("/api/v1/mascotas/{$id}", ['raza' => 'Labrador'])->assertOk()->assertJsonPath('data.raza', 'Labrador');
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")->assertJsonPath('data.mascotas.0.nombre', 'Luna');

    ($this->api)()->postJson("/api/v1/unidades/{$this->unidad->id}/mascotas", ['nombre' => 'X', 'especie' => 'dinosaurio'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.especie.0', 'La especie es perro, gato, ave u otro.');

    ($this->api)()->deleteJson("/api/v1/mascotas/{$id}")->assertNoContent();
    ($this->api)()->getJson("/api/v1/unidades/{$this->unidad->id}")->assertJsonPath('data.mascotas', []);
});

it('exige unidades.editar para registrar', function () {
    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);

    $this->withToken($guardia)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->postJson("/api/v1/unidades/{$this->unidad->id}/vehiculos", ['placa' => 'PBA-1234', 'tipo' => 'auto'])
        ->assertForbidden();
});
