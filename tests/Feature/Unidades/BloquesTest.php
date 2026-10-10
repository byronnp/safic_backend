<?php

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    $this->bloque = enCondominio($this->condominio, fn () => Bloque::factory()->create(['nombre' => 'Torre A', 'orden' => 1]));
});

it('cambia el nombre y el orden de un bloque', function () {
    ($this->api)()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['nombre' => 'Torre Norte', 'orden' => 5])
        ->assertOk()->assertJsonPath('data.nombre', 'Torre Norte')->assertJsonPath('data.orden', 5);

    ($this->api)()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['orden' => 2])->assertOk()
        ->assertJsonPath('data.nombre', 'Torre Norte')->assertJsonPath('data.orden', 2);
});

it('puede conservar su propio nombre pero no tomar el de otro bloque', function () {
    enCondominio($this->condominio, fn () => Bloque::factory()->create(['nombre' => 'Torre B']));

    ($this->api)()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['nombre' => 'Torre A', 'orden' => 3])->assertOk();
    ($this->api)()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['nombre' => 'Torre B'])->assertStatus(422)->assertJsonPath('error.fields.nombre.0', fn ($m) => is_string($m));
    ($this->api)()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['nombre' => ''])->assertStatus(422);
});

it('elimina un bloque sin unidades', function () {
    ($this->api)()->deleteJson("/api/v1/bloques/{$this->bloque->id}")->assertNoContent();

    expect(enCondominio($this->condominio, fn () => Bloque::count()))->toBe(0);
    ($this->api)()->deleteJson("/api/v1/bloques/{$this->bloque->id}")->assertNotFound();
});

it('no elimina un bloque con unidades', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->count(2)->create(['bloque_id' => $this->bloque->id]));

    ($this->api)()->deleteJson("/api/v1/bloques/{$this->bloque->id}")
        ->assertStatus(409)->assertJsonPath('error.code', 'BLOQUE_CON_UNIDADES')->assertJsonPath('error.details.unidades', 2);

    expect(enCondominio($this->condominio, fn () => Bloque::count()))->toBe(1);
});

it('solo quien edita unidades cambia o elimina bloques', function () {
    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $como = fn () => $this->withToken($guardia)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $como()->patchJson("/api/v1/bloques/{$this->bloque->id}", ['nombre' => 'X'])->assertForbidden();
    $como()->deleteJson("/api/v1/bloques/{$this->bloque->id}")->assertForbidden();
});

it('no toca bloques de otro condominio', function () {
    $otro = Condominio::factory()->create();
    $ajeno = enCondominio($otro, fn () => Bloque::factory()->create(['nombre' => 'Ajeno']));

    ($this->api)()->patchJson("/api/v1/bloques/{$ajeno->id}", ['nombre' => 'Mío'])->assertNotFound();
    ($this->api)()->deleteJson("/api/v1/bloques/{$ajeno->id}")->assertNotFound();
    expect(enCondominio($otro, fn () => Bloque::first()->nombre))->toBe('Ajeno');
});
