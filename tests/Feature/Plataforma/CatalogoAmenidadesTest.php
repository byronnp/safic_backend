<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;

function tipoCatalogo(array $datos = []): AmenidadCatalogo
{
    static $n = 0;

    return AmenidadCatalogo::create($datos + [
        'nombre' => 'Tipo '.++$n, 'categoria' => 'social', 'reservable' => false, 'esencial' => false,
        'requiere_aprobacion' => false, 'activa' => true, 'orden' => $n,
    ]);
}

beforeEach(function () {
    $this->superAdmin = User::factory()->dePlataforma()->create();
    $this->token = auth('api')->tokenById($this->superAdmin->id);
    $this->api = fn () => $this->withToken($this->token);
    $this->datos = fn (array $cambios = []) => $cambios + [
        'nombre' => 'Sala de cine', 'categoria' => 'recreacion', 'reservable' => true, 'capacidad' => 20, 'duracion_maxima_min' => 180,
    ];
});

it('lista el catálogo con en cuántos condominios se usa cada tipo', function () {
    $piscina = tipoCatalogo(['nombre' => 'Piscina', 'reservable' => true]);
    $sauna = tipoCatalogo(['nombre' => 'Sauna', 'activa' => false]);
    [$a, $b] = [Condominio::factory()->create(), Condominio::factory()->create()];
    foreach ([$a, $b] as $c) {
        enCondominio($c, function () use ($piscina) {
            // Dos registros en el mismo condominio cuentan como un solo condominio
            CondominioAmenidad::create(['amenidad_catalogo_id' => $piscina->id, 'nombre' => 'Piscina 1', 'cantidad' => 1, 'reservable' => true]);
            CondominioAmenidad::create(['amenidad_catalogo_id' => $piscina->id, 'nombre' => 'Piscina 2', 'cantidad' => 1, 'reservable' => true]);
        });
    }

    $r = ($this->api)()->getJson('/api/v1/plataforma/catalogo-amenidades')->assertOk();
    $porNombre = collect($r->json('data'))->keyBy('nombre');

    expect($porNombre['Piscina'])->toMatchArray(['uso' => 2, 'activa' => true, 'reservable' => true])
        ->and($porNombre['Sauna'])->toMatchArray(['uso' => 0, 'activa' => false]);
});

it('junta las amenidades propias de todos los condominios sin mezclarlas', function () {
    $piscina = tipoCatalogo(['nombre' => 'Piscina']);
    [$a, $b] = [Condominio::factory()->create(['nombre' => 'Brisas del Mar']), Condominio::factory()->create(['nombre' => 'Jardines del Valle'])];
    enCondominio($a, fn () => CondominioAmenidad::create(['nombre' => 'Muelle', 'categoria' => 'recreacion', 'cantidad' => 1, 'reservable' => true]));
    enCondominio($b, function () use ($piscina) {
        CondominioAmenidad::create(['nombre' => 'Huerto', 'categoria' => 'recreacion', 'cantidad' => 1]);
        CondominioAmenidad::create(['amenidad_catalogo_id' => $piscina->id, 'nombre' => 'Piscina', 'cantidad' => 1]);
    });

    $propias = collect(($this->api)()->getJson('/api/v1/plataforma/catalogo-amenidades/propias')->assertOk()->json('data'));

    expect($propias->pluck('nombre')->sort()->values()->all())->toBe(['Huerto', 'Muelle'])
        ->and($propias->firstWhere('nombre', 'Muelle'))->toMatchArray(['condominio' => 'Brisas del Mar', 'condominio_id' => $a->id, 'reservable' => true])
        ->and($propias->firstWhere('nombre', 'Huerto')['condominio_id'])->toBe($b->id);
});

it('crea un tipo con sus valores sugeridos', function () {
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['requiere_aprobacion' => true]))
        ->assertCreated()
        ->assertJsonPath('message', 'Amenidad agregada al catálogo.')
        ->assertJsonPath('data.nombre', 'Sala de cine')
        ->assertJsonPath('data.reservable', true)
        ->assertJsonPath('data.requiere_aprobacion', true)
        ->assertJsonPath('data.capacidad', 20)
        ->assertJsonPath('data.duracion_maxima_min', 180)
        ->assertJsonPath('data.activa', true);

    expect(AmenidadCatalogo::where('nombre', 'Sala de cine')->exists())->toBeTrue();
});

it('valida los datos del tipo', function () {
    tipoCatalogo(['nombre' => 'Piscina']);

    $r = ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', [])->assertStatus(422);
    expect($r->json('error.fields.nombre.0'))->toBe('Escribe el nombre de la amenidad.')
        ->and($r->json('error.fields.categoria.0'))->toBe('Elige la categoría.');

    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['nombre' => 'piscina']))->assertStatus(422)->assertJsonPath('error.fields.nombre.0', 'Ya existe una amenidad con ese nombre en el catálogo.');
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['categoria' => 'otra']))->assertStatus(422);
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['capacidad' => 0]))->assertStatus(422)->assertJsonPath('error.fields.capacidad.0', 'La capacidad va de 1 a 9999.');
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['duracion_maxima_min' => 5]))->assertStatus(422);
});

it('una esencial no se reserva y solo una reservable pide aprobación', function () {
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['esencial' => true, 'reservable' => true]))
        ->assertStatus(422)->assertJsonPath('error.fields.esencial.0', fn ($m) => str_contains($m, 'no se reserva'));
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)(['reservable' => false, 'requiere_aprobacion' => true]))
        ->assertStatus(422)->assertJsonPath('error.fields.requiere_aprobacion.0', 'Solo una amenidad reservable puede requerir aprobación.');

    // También sobre lo ya guardado: editar solo "esencial" de una reservable
    $reservable = tipoCatalogo(['nombre' => 'Salón', 'reservable' => true]);
    ($this->api)()->patchJson("/api/v1/plataforma/catalogo-amenidades/{$reservable->id}", ['esencial' => true])->assertStatus(422)->assertJsonPath('error.fields.esencial.0', fn ($m) => str_contains($m, 'no se reserva'));
    expect($reservable->fresh()->esencial)->toBeFalse();
});

it('edita un tipo sin alterar la copia de los condominios que ya lo usan', function () {
    $bbq = tipoCatalogo(['nombre' => 'Área BBQ', 'reservable' => true, 'capacidad' => 20, 'duracion_maxima_min' => 240]);
    $condominio = Condominio::factory()->create();
    enCondominio($condominio, fn () => CondominioAmenidad::create(['amenidad_catalogo_id' => $bbq->id, 'nombre' => 'Área BBQ', 'cantidad' => 1, 'reservable' => true, 'requiere_aprobacion' => false]));

    ($this->api)()->patchJson("/api/v1/plataforma/catalogo-amenidades/{$bbq->id}", ['capacidad' => 30, 'requiere_aprobacion' => true, 'activa' => false])
        ->assertOk()->assertJsonPath('data.capacidad', 30)->assertJsonPath('data.requiere_aprobacion', true)->assertJsonPath('data.activa', false)->assertJsonPath('data.uso', 1);

    expect(enCondominio($condominio, fn () => CondominioAmenidad::sole()->requiere_aprobacion))->toBeFalse();

    // Dejar de ser reservable quita la duración sugerida
    ($this->api)()->patchJson("/api/v1/plataforma/catalogo-amenidades/{$bbq->id}", ['reservable' => false, 'requiere_aprobacion' => false])->assertOk()->assertJsonPath('data.duracion_maxima_min', null);
});

it('elimina un tipo que nadie usa y solo desactiva el que está en uso', function () {
    $libre = tipoCatalogo(['nombre' => 'Libre']);
    $enUso = tipoCatalogo(['nombre' => 'En uso']);
    $condominio = Condominio::factory()->create();
    enCondominio($condominio, fn () => CondominioAmenidad::create(['amenidad_catalogo_id' => $enUso->id, 'nombre' => 'En uso', 'cantidad' => 1]));

    ($this->api)()->deleteJson("/api/v1/plataforma/catalogo-amenidades/{$enUso->id}")->assertStatus(409)
        ->assertJsonPath('error.code', 'AMENIDAD_EN_USO')->assertJsonPath('error.details.condominios', 1);
    ($this->api)()->deleteJson("/api/v1/plataforma/catalogo-amenidades/{$libre->id}")->assertOk();

    expect(AmenidadCatalogo::whereKey($libre->id)->exists())->toBeFalse()->and(AmenidadCatalogo::whereKey($enUso->id)->exists())->toBeTrue();
    ($this->api)()->deleteJson('/api/v1/plataforma/catalogo-amenidades/999999')->assertNotFound();
});

it('promueve una amenidad propia al catálogo y el condominio la conserva', function () {
    $condominio = Condominio::factory()->create();
    $muelle = enCondominio($condominio, fn () => CondominioAmenidad::create(['nombre' => 'Muelle', 'categoria' => 'deporte', 'cantidad' => 1, 'reservable' => true, 'requiere_aprobacion' => true]));

    ($this->api)()->postJson("/api/v1/plataforma/catalogo-amenidades/propias/{$condominio->id}/{$muelle->id}/promover")
        ->assertCreated()
        ->assertJsonPath('message', 'Amenidad promovida al catálogo global.')
        ->assertJsonPath('data.nombre', 'Muelle')
        ->assertJsonPath('data.categoria', 'deporte')
        ->assertJsonPath('data.reservable', true)
        ->assertJsonPath('data.requiere_aprobacion', true);

    $tipo = AmenidadCatalogo::where('nombre', 'Muelle')->sole();
    expect(enCondominio($condominio, fn () => CondominioAmenidad::sole()->amenidad_catalogo_id))->toBe($tipo->id);
    // Ya no es propia
    expect(($this->api)()->getJson('/api/v1/plataforma/catalogo-amenidades/propias')->json('data'))->toBe([]);
});

it('no promueve lo que ya está en el catálogo, lo que no es propio ni lo de otro condominio', function () {
    tipoCatalogo(['nombre' => 'Muelle']);
    [$a, $b] = [Condominio::factory()->create(), Condominio::factory()->create()];
    $repetida = enCondominio($a, fn () => CondominioAmenidad::create(['nombre' => 'muelle', 'cantidad' => 1]));
    $delCatalogo = enCondominio($a, fn () => CondominioAmenidad::create(['amenidad_catalogo_id' => tipoCatalogo()->id, 'nombre' => 'Con tipo', 'cantidad' => 1]));
    $propiaDeA = enCondominio($a, fn () => CondominioAmenidad::create(['nombre' => 'Huerto', 'cantidad' => 1]));

    ($this->api)()->postJson("/api/v1/plataforma/catalogo-amenidades/propias/{$a->id}/{$repetida->id}/promover")->assertStatus(409)->assertJsonPath('error.code', 'AMENIDAD_EN_CATALOGO');
    ($this->api)()->postJson("/api/v1/plataforma/catalogo-amenidades/propias/{$a->id}/{$delCatalogo->id}/promover")->assertNotFound();
    // Pedirla con el condominio equivocado no la encuentra: cada condominio se mira en su propio contexto
    ($this->api)()->postJson("/api/v1/plataforma/catalogo-amenidades/propias/{$b->id}/{$propiaDeA->id}/promover")->assertNotFound();
    expect(AmenidadCatalogo::where('nombre', 'Huerto')->exists())->toBeFalse();
});

it('solo la plataforma con permiso entra, no un administrador de condominio', function () {
    $condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($condominio, Rol::Administrador);
    cambiarDeUsuario();
    $admin = fn () => $this->withToken($token);

    $admin()->getJson('/api/v1/plataforma/catalogo-amenidades')->assertForbidden();
    $admin()->postJson('/api/v1/plataforma/catalogo-amenidades', ($this->datos)())->assertForbidden();
    $admin()->postJson('/api/v1/plataforma/catalogo-amenidades/propias/1/1/promover')->assertForbidden();
    expect(AmenidadCatalogo::where('nombre', 'Sala de cine')->exists())->toBeFalse();

    // Cobranza es de plataforma pero no gestiona condominios
    $cobranza = User::factory()->dePlataforma(Rol::Cobranza)->create();
    cambiarDeUsuario();
    $this->withToken(auth('api')->tokenById($cobranza->id))->getJson('/api/v1/plataforma/catalogo-amenidades')->assertForbidden();
});
