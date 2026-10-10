<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\AmenidadFoto;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);

    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $piscina = AmenidadCatalogo::create(['nombre' => 'Piscina', 'categoria' => 'recreacion', 'reservable' => true, 'esencial' => false, 'requiere_aprobacion' => false, 'activa' => true, 'orden' => 1]);
    $this->amenidad = ($this->api)()->postJson('/api/v1/amenidades', ['origen' => 'catalogo', 'amenidad_catalogo_id' => $piscina->id, 'cantidad' => 1])->json('data.0.id');

    $this->foto = fn (string $nombre = 'piscina.jpg', int $ancho = 800) => UploadedFile::fake()->image($nombre, $ancho, 600);
    $this->subir = fn ($archivo = null, ?int $amenidad = null) => ($this->api)()->post(
        '/api/v1/amenidades/'.($amenidad ?? $this->amenidad).'/fotos',
        ['foto' => $archivo ?? ($this->foto)()],
        ['Accept' => 'application/json'],
    );
    $this->fotos = fn () => collect(($this->api)()->getJson('/api/v1/amenidades')->json('data'))->firstWhere('id', $this->amenidad)['fotos'];
});

it('sube una foto: queda en el bucket del condominio y la lista trae un enlace temporal', function () {
    $r = ($this->subir)()->assertCreated()->assertJsonPath('message', 'Foto agregada.');

    $fotos = $r->json('data.fotos');
    expect($fotos)->toHaveCount(1)->and($fotos[0]['orden'])->toBe(1)->and($fotos[0]['url'])->toBeString()->not->toBeEmpty();

    $ruta = enCondominio($this->condominio, fn () => AmenidadFoto::query()->firstOrFail()->ruta);
    expect($ruta)->toStartWith("condominios/{$this->condominio->id}/amenidades/")->and($ruta)->not->toContain('piscina');
    Storage::disk('local')->assertExists($ruta);
});

it('las fotos van en orden de subida y no pasan de cinco', function () {
    foreach (range(1, 5) as $n) {
        ($this->subir)(($this->foto)("f$n.jpg"))->assertCreated();
    }

    expect(collect(($this->fotos)())->pluck('orden')->all())->toBe([1, 2, 3, 4, 5]);

    ($this->subir)()->assertStatus(422)->assertJsonPath('error.code', 'LIMITE_FOTOS');
    expect(enCondominio($this->condominio, fn () => AmenidadFoto::query()->count()))->toBe(5)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(5); // la sexta no queda huérfana
});

it('rechaza lo que no es una foto JPG o PNG razonable', function () {
    ($this->subir)(UploadedFile::fake()->create('plano.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('foto', 'error.fields');
    ($this->subir)(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
    ($this->subir)(UploadedFile::fake()->image('enorme.jpg', 800, 600)->size(6000))->assertStatus(422);
    ($this->subir)(UploadedFile::fake()->image('chica.jpg', 100, 100))->assertStatus(422);
    ($this->api)()->postJson("/api/v1/amenidades/{$this->amenidad}/fotos", [])->assertStatus(422);

    expect(enCondominio($this->condominio, fn () => AmenidadFoto::query()->count()))->toBe(0);
});

it('quitar una foto la borra del bucket y vuelve a numerar; si era la portada, la siguiente lo es', function () {
    foreach (['a', 'b', 'c'] as $n) {
        ($this->subir)(($this->foto)("$n.jpg"))->assertCreated();
    }
    $antes = enCondominio($this->condominio, fn () => AmenidadFoto::query()->orderBy('orden')->get());
    $portada = $antes[0];

    ($this->api)()->deleteJson("/api/v1/amenidades/{$this->amenidad}/fotos/{$portada->id}")->assertOk()->assertJsonPath('message', 'Foto eliminada.');

    $despues = enCondominio($this->condominio, fn () => AmenidadFoto::query()->orderBy('orden')->get());
    expect($despues->pluck('id')->all())->toBe([$antes[1]->id, $antes[2]->id])
        ->and($despues->pluck('orden')->all())->toBe([1, 2]);
    Storage::disk('local')->assertMissing($portada->ruta);
    Storage::disk('local')->assertExists($antes[1]->ruta);
});

it('reordena con la lista completa y rechaza una lista desactualizada', function () {
    foreach (['a', 'b', 'c'] as $n) {
        ($this->subir)(($this->foto)("$n.jpg"))->assertCreated();
    }
    $ids = enCondominio($this->condominio, fn () => AmenidadFoto::query()->orderBy('orden')->pluck('id')->all());
    [$a, $b, $c] = $ids;

    ($this->api)()->putJson("/api/v1/amenidades/{$this->amenidad}/fotos/orden", ['ids' => [$c, $a, $b]])->assertOk();
    expect(collect(($this->fotos)())->pluck('id')->all())->toBe([$c, $a, $b]);

    foreach ([[$c, $a], [$c, $a, $b, 999], [$c, $c, $a]] as $mala) {
        ($this->api)()->putJson("/api/v1/amenidades/{$this->amenidad}/fotos/orden", ['ids' => $mala])->assertStatus(409)->assertJsonPath('error.code', 'FOTOS_DESACTUALIZADAS');
    }
    ($this->api)()->putJson("/api/v1/amenidades/{$this->amenidad}/fotos/orden", [])->assertStatus(422);
    expect(collect(($this->fotos)())->pluck('id')->all())->toBe([$c, $a, $b]);
});

it('no se tocan las fotos de otra amenidad ni de otro condominio', function () {
    ($this->subir)()->assertCreated();
    $foto = enCondominio($this->condominio, fn () => AmenidadFoto::query()->firstOrFail());
    $bbq = AmenidadCatalogo::create(['nombre' => 'BBQ', 'categoria' => 'social', 'reservable' => true, 'esencial' => false, 'requiere_aprobacion' => false, 'activa' => true, 'orden' => 2]);
    $otra = ($this->api)()->postJson('/api/v1/amenidades', ['origen' => 'catalogo', 'amenidad_catalogo_id' => $bbq->id, 'cantidad' => 1])->json('data.0.id');

    // La foto existe, pero es de otra amenidad
    ($this->api)()->deleteJson("/api/v1/amenidades/{$otra}/fotos/{$foto->id}")->assertNotFound();

    $ajeno = Condominio::factory()->create();
    [, $token] = usuarioConToken($ajeno);
    cambiarDeUsuario();
    $conAjeno = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $ajeno->id);
    $conAjeno()->deleteJson("/api/v1/amenidades/{$this->amenidad}/fotos/{$foto->id}")->assertNotFound();
    $conAjeno()->post("/api/v1/amenidades/{$this->amenidad}/fotos", ['foto' => ($this->foto)()], ['Accept' => 'application/json'])->assertNotFound();

    Storage::disk('local')->assertExists($foto->ruta);
});

it('solo quien gestiona amenidades sube, quita u ordena fotos', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $guardia = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $guardia()->post("/api/v1/amenidades/{$this->amenidad}/fotos", ['foto' => ($this->foto)()], ['Accept' => 'application/json'])->assertForbidden();
    $guardia()->deleteJson("/api/v1/amenidades/{$this->amenidad}/fotos/1")->assertForbidden();
    $guardia()->putJson("/api/v1/amenidades/{$this->amenidad}/fotos/orden", ['ids' => [1]])->assertForbidden();
});
