<?php

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn (?string $t = null) => $this->withToken($t ?? $token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    $this->datos = [
        'tipo_documento' => 'cedula', 'documento' => '1710034065', 'nombres' => 'Lucía',
        'apellidos' => 'Paredes', 'telefono' => '099 123 4534', 'email' => ' Lucia@Correo.EC ',
    ];
});

it('registra una persona con el documento y el teléfono cifrados', function () {
    ($this->api)()->postJson('/api/v1/personas', $this->datos)
        ->assertCreated()
        ->assertJsonPath('data.documento', '1710034065')
        ->assertJsonPath('data.telefono', '0991234534')
        ->assertJsonPath('data.email', 'lucia@correo.ec')
        ->assertJsonPath('data.nombre_completo', 'Lucía Paredes')
        ->assertJsonPath('data.datos_enmascarados', false);

    $fila = enCondominio($this->condominio, fn () => DB::table('personas')->first());
    expect($fila->documento)->not->toContain('1710034065')
        ->and($fila->telefono)->not->toContain('0991234534')
        ->and($fila->documento_hash)->toHaveLength(64);
});

it('valida la cédula ecuatoriana y el celular', function () {
    ($this->api)()->postJson('/api/v1/personas', [...$this->datos, 'documento' => '1710034066', 'telefono' => '022345678'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.documento.0', 'La cédula no es válida.')
        ->assertJsonPath('error.fields.telefono.0', 'El celular tiene 10 dígitos y empieza con 09.');
});

it('no repite el documento en el condominio', function () {
    ($this->api)()->postJson('/api/v1/personas', $this->datos)->assertCreated();

    ($this->api)()->postJson('/api/v1/personas', [...$this->datos, 'nombres' => 'Otra'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields.documento.0', 'Ya existe una persona con ese documento en el condominio.');
});

it('busca por nombre o por documento exacto', function () {
    ($this->api)()->postJson('/api/v1/personas', $this->datos)->assertCreated();
    enCondominio($this->condominio, fn () => Persona::factory()->create(['nombres' => 'Diego', 'apellidos' => 'Mora']));

    ($this->api)()->getJson('/api/v1/personas?buscar=paredes')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombres', 'Lucía');
    ($this->api)()->getJson('/api/v1/personas?buscar=1710034065')->assertJsonCount(1, 'data');
    ($this->api)()->getJson('/api/v1/personas?buscar=171003')->assertJsonCount(0, 'data');
    ($this->api)()->getJson('/api/v1/personas')->assertJsonPath('meta.pagination.total', 2);
});

it('enmascara los datos personales para quien no tiene residentes.ver_datos', function () {
    $id = ($this->api)()->postJson('/api/v1/personas', $this->datos)->json('data.id');
    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();

    ($this->api)($guardia)->getJson("/api/v1/personas/{$id}")
        ->assertOk()
        ->assertJsonPath('data.documento', '17••••••65')
        ->assertJsonPath('data.telefono', '09••••••34')
        ->assertJsonPath('data.email', 'l•••@correo.ec')
        ->assertJsonPath('data.nombres', 'Lucía')
        ->assertJsonPath('data.datos_enmascarados', true);
});

it('edita solo lo enviado y exige el documento al cambiar el tipo', function () {
    $id = ($this->api)()->postJson('/api/v1/personas', $this->datos)->json('data.id');

    ($this->api)()->patchJson("/api/v1/personas/{$id}", ['telefono' => '0987654321'])
        ->assertOk()
        ->assertJsonPath('data.telefono', '0987654321')
        ->assertJsonPath('data.documento', '1710034065');

    ($this->api)()->patchJson("/api/v1/personas/{$id}", ['tipo_documento' => 'pasaporte'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documento', 'error.fields');
});

it('exige unidades.editar para registrar', function () {
    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);

    ($this->api)($guardia)->postJson('/api/v1/personas', $this->datos)->assertForbidden();
});
