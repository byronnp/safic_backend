<?php

use App\Core\Audit\Auditoria;
use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $tipo = fn (array $datos) => AmenidadCatalogo::create($datos + ['categoria' => 'social', 'reservable' => false, 'esencial' => false, 'requiere_aprobacion' => false, 'activa' => true, 'orden' => 1]);
    $this->bbq = $tipo(['nombre' => 'Área BBQ', 'reservable' => true, 'capacidad' => 20, 'duracion_maxima_min' => 240]);
    $this->piscina = $tipo(['nombre' => 'Piscina', 'categoria' => 'recreacion', 'reservable' => true, 'requiere_aprobacion' => true, 'capacidad' => 30]);
    $this->ascensor = $tipo(['nombre' => 'Ascensor', 'categoria' => 'servicios', 'esencial' => true]);
    $this->inactiva = $tipo(['nombre' => 'Sauna', 'activa' => false]);

    $this->agregar = fn (array $datos) => ($this->api)()->postJson('/api/v1/amenidades', $datos);
    $this->lista = fn () => collect(($this->api)()->getJson('/api/v1/amenidades')->assertOk()->json('data'))->keyBy('nombre');
});

it('sin amenidades la lista está vacía y el catálogo solo trae los tipos activos', function () {
    ($this->api)()->getJson('/api/v1/amenidades')->assertOk()->assertJsonPath('data', []);

    $catalogo = collect(($this->api)()->getJson('/api/v1/amenidades/catalogo')->assertOk()->json('data'))->pluck('nombre')->sort()->values()->all();
    expect($catalogo)->toHaveCount(3)->and($catalogo)->toContain('Piscina', 'Ascensor', 'Área BBQ')->not->toContain('Sauna');
});

it('agrega del catálogo una reservable y copia sus valores', function () {
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1, 'ubicacion' => 'Área social'])
        ->assertCreated()->assertJsonPath('message', 'Amenidad agregada.')
        ->assertJsonPath('data.0.nombre', 'Piscina')
        ->assertJsonPath('data.0.origen', 'catalogo')
        ->assertJsonPath('data.0.tipo', 'Piscina')
        ->assertJsonPath('data.0.categoria', 'recreacion')
        ->assertJsonPath('data.0.reservable', true)
        ->assertJsonPath('data.0.requiere_aprobacion', true)
        ->assertJsonPath('data.0.capacidad', 30)
        ->assertJsonPath('data.0.ubicacion', 'Área social')
        ->assertJsonPath('data.0.estado', 'disponible');
});

it('varias reservables del mismo tipo son registros separados y la numeración sigue donde iba', function () {
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->bbq->id, 'cantidad' => 2])->assertCreated()
        ->assertJsonPath('message', '2 amenidades agregadas.')
        ->assertJsonPath('data.0.nombre', 'Área BBQ 1')->assertJsonPath('data.1.nombre', 'Área BBQ 2')
        ->assertJsonPath('data.0.cantidad', 1);

    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->bbq->id, 'cantidad' => 1])->assertCreated()
        ->assertJsonPath('data.0.nombre', 'Área BBQ 3');
});

it('una que no se reserva es un solo registro con su cantidad', function () {
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->ascensor->id, 'cantidad' => 3])->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Ascensor (3)')
        ->assertJsonPath('data.0.cantidad', 3)
        ->assertJsonPath('data.0.esencial', true);
});

it('agrega una propia del condominio con su categoría', function () {
    ($this->agregar)(['origen' => 'propia', 'nombre' => 'Huerto comunitario', 'categoria' => 'recreacion', 'reservable' => false, 'cantidad' => 1, 'ubicacion' => 'Detrás de torre B'])
        ->assertCreated()
        ->assertJsonPath('data.0.origen', 'propia')
        ->assertJsonPath('data.0.tipo', null)
        ->assertJsonPath('data.0.categoria', 'recreacion')
        ->assertJsonPath('data.0.capacidad', null);

    ($this->agregar)(['origen' => 'propia', 'nombre' => 'Muelle', 'categoria' => 'recreacion', 'reservable' => true, 'cantidad' => 2])->assertCreated()
        ->assertJsonPath('data.0.nombre', 'Muelle 1')->assertJsonPath('data.1.nombre', 'Muelle 2');
});

it('una propia no puede llamarse como una del catálogo ni repetir un nombre', function () {
    ($this->agregar)(['origen' => 'propia', 'nombre' => 'piscina', 'categoria' => 'recreacion', 'cantidad' => 1])->assertStatus(422)
        ->assertJsonPath('error.fields.nombre.0', fn ($m) => str_contains($m, 'ya existe en el catálogo'));

    ($this->agregar)(['origen' => 'propia', 'nombre' => 'Huerto', 'categoria' => 'recreacion', 'cantidad' => 1])->assertCreated();
    ($this->agregar)(['origen' => 'propia', 'nombre' => 'Huerto', 'categoria' => 'recreacion', 'cantidad' => 1])->assertStatus(422)
        ->assertJsonPath('error.code', 'AMENIDAD_EXISTE');
    expect(enCondominio($this->condominio, fn () => CondominioAmenidad::count()))->toBe(1);
});

it('valida lo que se agrega', function () {
    ($this->agregar)([])->assertStatus(422)->assertJsonPath('error.fields.origen.0', 'Elige si es del catálogo o propia.');
    ($this->agregar)(['origen' => 'catalogo', 'cantidad' => 1])->assertStatus(422)->assertJsonPath('error.fields.amenidad_catalogo_id.0', 'Elige un tipo del catálogo.');
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->inactiva->id, 'cantidad' => 1])->assertStatus(422)->assertJsonPath('error.fields.amenidad_catalogo_id.0', 'Ese tipo ya no está en el catálogo.');
    ($this->agregar)(['origen' => 'propia', 'cantidad' => 1])->assertStatus(422)
        ->assertJsonPath('error.fields.nombre.0', 'Escribe el nombre de la amenidad.')
        ->assertJsonPath('error.fields.categoria.0', 'Elige la categoría.');
    ($this->agregar)(['origen' => 'propia', 'nombre' => 'X', 'categoria' => 'otra', 'cantidad' => 51])->assertStatus(422)
        ->assertJsonPath('error.fields.nombre.0', 'El nombre tiene mínimo 2 caracteres.')
        ->assertJsonPath('error.fields.categoria.0', 'Elige recreación, deporte, social, servicios o seguridad.');
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->bbq->id, 'cantidad' => 0])->assertStatus(422)->assertJsonPath('error.fields.cantidad.0', 'La cantidad va de 1 a 50.');
    expect(enCondominio($this->condominio, fn () => CondominioAmenidad::count()))->toBe(0);
});

it('pone una amenidad en mantenimiento hasta una fecha y la saca', function () {
    $id = ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1])->json('data.0.id');
    $hasta = hoyLocal()->addDays(10)->toDateString();

    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['mantenimiento_hasta' => $hasta])->assertOk()
        ->assertJsonPath('data.estado', 'mantenimiento')->assertJsonPath('data.mantenimiento_hasta', $hasta);

    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['mantenimiento_hasta' => null])->assertOk()
        ->assertJsonPath('data.estado', 'disponible')->assertJsonPath('data.mantenimiento_hasta', null);

    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['mantenimiento_hasta' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)
        ->assertJsonPath('error.fields.mantenimiento_hasta.0', 'La fecha de fin del mantenimiento no puede ser pasada.');

    // Un mantenimiento cuya fecha ya pasó termina solo
    enCondominio($this->condominio, fn () => CondominioAmenidad::whereKey($id)->update(['mantenimiento_hasta' => hoyLocal()->subDay()->toDateString()]));
    expect(($this->lista)()['Piscina']['estado'])->toBe('disponible');
});

it('cambia la ubicación y desactiva o reactiva', function () {
    $id = ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1])->json('data.0.id');

    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['ubicacion' => 'Torre A · PB'])->assertOk()->assertJsonPath('data.ubicacion', 'Torre A · PB');
    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['activa' => false])->assertOk()->assertJsonPath('data.estado', 'inactiva');
    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['activa' => true])->assertOk()->assertJsonPath('data.estado', 'disponible');
    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['ubicacion' => str_repeat('a', 81)])->assertStatus(422);
});

it('deja constancia de los cambios en la auditoría', function () {
    $id = ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1])->json('data.0.id');
    ($this->api)()->patchJson("/api/v1/amenidades/$id", ['activa' => false])->assertOk();

    $eventos = enCondominio($this->condominio, fn () => Auditoria::where('auditable_type', (new CondominioAmenidad)->getMorphClass())->orderBy('id')->pluck('event')->all());
    expect($eventos)->toBe(['created', 'updated']);
});

it('no mezcla amenidades entre condominios', function () {
    $otro = Condominio::factory()->create();
    $ajena = enCondominio($otro, fn () => CondominioAmenidad::create(['nombre' => 'Piscina', 'cantidad' => 1, 'reservable' => true]));

    expect(($this->lista)())->toHaveCount(0);
    ($this->api)()->patchJson("/api/v1/amenidades/{$ajena->id}", ['activa' => false])->assertNotFound();
    expect(enCondominio($otro, fn () => CondominioAmenidad::whereKey($ajena->id)->value('activa')))->toBeTrue();

    // El mismo nombre en otro condominio no choca
    ($this->agregar)(['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1])->assertCreated();
});

it('solo quien gestiona amenidades entra', function () {
    foreach ([Rol::Guardia, Rol::Residente, Rol::Contador] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/amenidades')->assertForbidden();
        $api()->getJson('/api/v1/amenidades/catalogo')->assertForbidden();
        $api()->postJson('/api/v1/amenidades', ['origen' => 'catalogo', 'amenidad_catalogo_id' => $this->piscina->id, 'cantidad' => 1])->assertForbidden();
        $api()->patchJson('/api/v1/amenidades/1', ['activa' => false])->assertForbidden();
    }
});
