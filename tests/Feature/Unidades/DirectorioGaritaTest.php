<?php

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Models\Vehiculo;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    $this->guardia = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    enCondominio($this->condominio, function () {
        $torre = Bloque::create(['nombre' => 'Torre A', 'orden' => 1]);
        $unidad = Unidad::factory()->create(['codigo' => 'A-102', 'bloque_id' => $torre->id]);
        $diego = Persona::factory()->create([
            'nombres' => 'Diego', 'apellidos' => 'Mora', 'telefono' => '0983307710',
            'tipo_documento' => 'cedula', 'documento' => '1710034065', 'email' => 'diego@example.com',
        ]);
        $antiguo = Persona::factory()->create(['nombres' => 'Marcos', 'apellidos' => 'Salas', 'telefono' => '0990001111']);

        Ocupante::create(['unidad_id' => $unidad->id, 'persona_id' => $diego->id, 'relacion' => 'inquilino', 'es_principal' => true, 'fecha_inicio' => now()->subYear()->toDateString()]);
        Ocupante::create(['unidad_id' => $unidad->id, 'persona_id' => $antiguo->id, 'relacion' => 'inquilino', 'es_principal' => false, 'fecha_inicio' => now()->subYears(3)->toDateString(), 'fecha_fin' => now()->subYear()->toDateString()]);
        Vehiculo::create(['unidad_id' => $unidad->id, 'placa' => 'PBC-4821', 'tipo' => 'auto', 'marca' => 'Kia', 'modelo' => 'Sportage', 'color' => 'Gris']);
        Vehiculo::create(['unidad_id' => $unidad->id, 'placa' => 'PBD-1000', 'tipo' => 'moto']);
    });
});

it('encuentra por placa, con o sin guion y en minúsculas, y marca la que coincide', function () {
    foreach (['PBC-4821', 'pbc4821', 'pbc 48'] as $buscar) {
        $r = ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar='.urlencode($buscar))->assertOk();
        expect($r->json('data'))->toHaveCount(1)
            ->and($r->json('data.0.unidad.codigo'))->toBe('A-102')
            ->and(collect($r->json('data.0.vehiculos'))->firstWhere('placa', 'PBC-4821')['coincide'])->toBeTrue()
            ->and(collect($r->json('data.0.vehiculos'))->firstWhere('placa', 'PBD-1000')['coincide'])->toBeFalse();
    }
});

it('encuentra por nombre del ocupante vigente y por código de unidad', function () {
    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=diego')->assertOk()->assertJsonCount(1, 'data');
    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=a-10')->assertOk()->assertJsonCount(1, 'data');
    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=zzzz')->assertOk()->assertJsonCount(0, 'data');
});

it('muestra nombre, unidad, teléfono completo y vehículo, y nada más', function () {
    $r = ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=PBC')->assertOk();

    expect($r->json('data.0'))->toBe([
        'unidad' => ['id' => $r->json('data.0.unidad.id'), 'codigo' => 'A-102', 'tipo' => $r->json('data.0.unidad.tipo'), 'bloque' => 'Torre A'],
        'ocupantes' => [['nombre' => 'Diego Mora', 'relacion' => 'inquilino', 'telefono' => '0983307710']],
        'vehiculos' => [
            ['placa' => 'PBC-4821', 'descripcion' => 'Kia Sportage · Gris', 'coincide' => true],
            ['placa' => 'PBD-1000', 'descripcion' => '', 'coincide' => false],
        ],
    ]);

    expect(Unidad::TIPOS)->toContain($r->json('data.0.unidad.tipo'));

    // Ni cédula ni correo viajan en la respuesta, ni de quien ya no vive ahí
    expect($r->getContent())->not->toContain('1710034065')
        ->not->toContain('diego@example.com')
        ->not->toContain('Marcos')
        ->not->toContain('0990001111');
});

it('no acepta búsquedas vacías ni de un solo carácter', function () {
    foreach (['', ' ', 'a', '--', '%'] as $buscar) {
        ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar='.urlencode($buscar))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
    }
    ($this->guardia)()->getJson('/api/v1/garita/directorio')->assertStatus(422);
});

it('limita a 20 unidades por búsqueda', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->count(25)->sequence(fn ($s) => ['codigo' => 'MASIVA-'.$s->index])->create());

    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=masiva')->assertOk()->assertJsonCount(20, 'data');
});

it('exige garita.directorio: un residente no entra', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Residente);

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->getJson('/api/v1/garita/directorio?buscar=diego')->assertForbidden();
});

it('el administrador también lo consulta', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->getJson('/api/v1/garita/directorio?buscar=diego')->assertOk();
});

it('no ve unidades de otro condominio', function () {
    $otro = Condominio::factory()->create();
    enCondominio($otro, fn () => Unidad::factory()->create(['codigo' => 'ZZ-900']));

    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=zz-900')->assertOk()->assertJsonCount(0, 'data');
});

it('el teléfono sigue enmascarado en las demás pantallas para el guardia', function () {
    $persona = enCondominio($this->condominio, fn () => Persona::where('nombres', 'Diego')->sole());

    ($this->guardia)()->getJson("/api/v1/personas/{$persona->id}")->assertOk()
        ->assertJsonPath('data.datos_enmascarados', true)
        ->assertJsonMissing(['telefono' => '0983307710']);
});

it('limita la frecuencia de consultas', function () {
    foreach (range(1, 30) as $_) {
        ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=diego')->assertOk();
    }

    ($this->guardia)()->getJson('/api/v1/garita/directorio?buscar=diego')->assertStatus(429);
});
