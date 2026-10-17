<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\Condominio;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'guardia' => Rol::Guardia] as $clave => $rol) {
        [$u] = usuarioConToken($this->condominio, $rol);
        $this->usuarios[$clave] = $u;
    }
    $this->como = function (string $clave) {
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($this->usuarios[$clave]->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->salon = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Salón comunal', 'cantidad' => 1, 'reservable' => true, 'ubicacion' => 'Torre A']));
    $this->piscina = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Gimnasio', 'cantidad' => 1, 'reservable' => false]));
    $this->reglas = fn (array $extra = []) => $extra + [
        'abre' => '08:00', 'cierra' => '23:00', 'duracion_min_horas' => 2, 'duracion_max_horas' => 6, 'anticipacion_min_horas' => 48, 'anticipacion_max_dias' => 60,
        'capacidad' => 60, 'reservas_por_unidad_mes' => 1, 'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'costo' => '50.00', 'garantia' => '100.00',
        'reglamento' => 'Música hasta las 22:00.', 'requiere_aprobacion' => true, 'restringible_por_mora' => true,
    ];
});

it('lista solo las amenidades reservables, con reglas por defecto mientras no se configuran', function () {
    $r = ($this->como)('admin')->getJson('/api/v1/areas-comunes/areas')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0'))->toMatchArray(['nombre' => 'Salón comunal', 'configurada' => false, 'estado' => 'disponible', 'restringible_por_mora' => true])
        ->and($r->json('data.0.reglas.abre'))->toBe('08:00');
});

it('guarda las reglas del área y la aprobación y restricción en la amenidad', function () {
    $r = ($this->como)('admin')->putJson("/api/v1/areas-comunes/areas/{$this->salon->id}/reglas", ($this->reglas)(['restringible_por_mora' => false]))->assertOk();

    expect($r->json('data'))->toMatchArray(['configurada' => true, 'requiere_aprobacion' => true, 'restringible_por_mora' => false])
        ->and($r->json('data.reglas'))->toMatchArray(['costo' => '50.00', 'garantia' => '100.00', 'capacidad' => 60, 'duracion_max_horas' => 6, 'cierra' => '23:00']);
    expect(enCondominio($this->condominio, fn () => CondominioAmenidad::find($this->salon->id)->esencial))->toBeTrue();

    // Guardar otra vez actualiza, no duplica
    ($this->como)('admin')->putJson("/api/v1/areas-comunes/areas/{$this->salon->id}/reglas", ($this->reglas)(['costo' => '60.00']))->assertOk()->assertJsonPath('data.reglas.costo', '60.00');
});

it('valida el horario, las duraciones y los días', function () {
    $put = fn (array $extra) => ($this->como)('admin')->putJson("/api/v1/areas-comunes/areas/{$this->salon->id}/reglas", ($this->reglas)($extra));

    $put(['abre' => '22:00', 'cierra' => '10:00'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');
    $put(['duracion_min_horas' => 5, 'duracion_max_horas' => 2])->assertStatus(422);
    $put(['dias_semana' => []])->assertStatus(422);
    $put(['dias_semana' => [8]])->assertStatus(422);
    $put(['costo' => '-1'])->assertStatus(422);
});

it('una amenidad no reservable no es un área', function () {
    ($this->como)('admin')->putJson("/api/v1/areas-comunes/areas/{$this->piscina->id}/reglas", ($this->reglas)())->assertStatus(409)->assertJsonPath('error.code', 'AREA_NO_RESERVABLE');
    ($this->como)('admin')->getJson("/api/v1/areas-comunes/areas/{$this->piscina->id}")->assertNotFound();
});

it('otro condominio no ve ni cambia las áreas', function () {
    $otro = Condominio::factory()->create();
    $ajena = enCondominio($otro, fn () => CondominioAmenidad::create(['nombre' => 'Piscina', 'cantidad' => 1, 'reservable' => true]));

    ($this->como)('admin')->getJson("/api/v1/areas-comunes/areas/{$ajena->id}")->assertNotFound();
    ($this->como)('admin')->putJson("/api/v1/areas-comunes/areas/{$ajena->id}/reglas", ($this->reglas)())->assertNotFound();
});

it('solo quien gestiona las áreas comunes puede verlas o cambiarlas', function () {
    ($this->como)('tesorero')->getJson('/api/v1/areas-comunes/areas')->assertForbidden();
    ($this->como)('guardia')->putJson("/api/v1/areas-comunes/areas/{$this->salon->id}/reglas", ($this->reglas)())->assertForbidden();
});
