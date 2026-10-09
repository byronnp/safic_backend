<?php

use App\Core\Permissions\Rol;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local');

    $this->condominio = Condominio::factory()->create([
        'nombre' => 'Conjunto Jardines del Valle', 'ruc' => '1792345678001', 'razon_social' => 'Jardines SA',
        'telefono' => '022334455', 'email_contacto' => 'admin@jardines.ec', 'direccion' => 'Av. Ilaló',
    ]);
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    $this->png = fn (int $lado = 200) => UploadedFile::fake()->image('logo.png', $lado, $lado);
    $this->subir = fn (string $variante, ?UploadedFile $archivo = null) => ($this->api)()->post(
        "/api/v1/condominio/logo/{$variante}",
        ['archivo' => $archivo ?? ($this->png)()],
        ['Accept' => 'application/json'],
    );
});

it('muestra los datos del condominio', function () {
    ($this->api)()->getJson('/api/v1/condominio')->assertOk()
        ->assertJsonPath('data.nombre', 'Conjunto Jardines del Valle')
        ->assertJsonPath('data.ruc', '1792345678001')
        ->assertJsonPath('data.telefono', '022334455')
        ->assertJsonPath('data.marca.color_primario', null)
        ->assertJsonPath('data.marca.logo_claro_url', null);
});

it('edita nombre y contacto sin tocar lo demás ni lo que es de la plataforma', function () {
    ($this->api)()->patchJson('/api/v1/condominio', [
        'nombre' => 'Jardines del Valle', 'telefono' => '0998887766', 'email_contacto' => 'nuevo@jardines.ec',
        'ruc' => '0000000000001', 'razon_social' => 'Otra SA', 'total_unidades' => 9999, 'estado' => 'suspendido',
    ])->assertOk()->assertJsonPath('message', 'Cambios guardados.')
        ->assertJsonPath('data.nombre', 'Jardines del Valle')
        ->assertJsonPath('data.direccion', 'Av. Ilaló');

    $condominio = $this->condominio->fresh();
    expect($condominio->telefono)->toBe('0998887766')
        ->and($condominio->email_contacto)->toBe('nuevo@jardines.ec')
        ->and($condominio->ruc)->toBe('1792345678001')
        ->and($condominio->razon_social)->toBe('Jardines SA')
        ->and($condominio->total_unidades)->toBe(50)
        ->and($condominio->estado)->toBe('activo');
});

it('valida nombre, teléfono, correo y dirección', function () {
    $r = ($this->api)()->patchJson('/api/v1/condominio', [
        'nombre' => '', 'telefono' => '12345', 'email_contacto' => 'no-es-correo', 'direccion' => str_repeat('a', 201),
    ])->assertStatus(422);

    expect($r->json('error.fields.nombre.0'))->toBe('Escribe el nombre del condominio.')
        ->and($r->json('error.fields.telefono.0'))->toBe('Escribe un teléfono de 9 o 10 dígitos que empiece con 0.')
        ->and($r->json('error.fields.email_contacto.0'))->toBe('Escribe un correo válido.')
        ->and($r->json('error.fields.direccion.0'))->toBe('La dirección tiene máximo 200 caracteres.');
});

it('cambia la ubicación solo con provincia, cantón y parroquia que encajan', function () {
    sembrarCatalogos();

    ($this->api)()->patchJson('/api/v1/condominio', [
        'provincia_codigo' => '17', 'canton_codigo' => '1701', 'parroquia_codigo' => '170156',
        'latitud' => -0.18, 'longitud' => -78.47,
    ])->assertOk()
        ->assertJsonPath('data.provincia.codigo', '17')
        ->assertJsonPath('data.canton.codigo', '1701')
        ->assertJsonPath('data.parroquia.codigo', '170156')
        ->assertJsonPath('data.latitud', '-0.180000');

    ($this->api)()->patchJson('/api/v1/condominio', ['provincia_codigo' => '17', 'canton_codigo' => '0901', 'parroquia_codigo' => '170156'])
        ->assertStatus(422)->assertJsonPath('error.fields.canton_codigo.0', 'El cantón no pertenece a la provincia elegida.');
    ($this->api)()->patchJson('/api/v1/condominio', ['provincia_codigo' => '17', 'canton_codigo' => '1701', 'parroquia_codigo' => '010101'])
        ->assertStatus(422)->assertJsonPath('error.fields.parroquia_codigo.0', 'La parroquia no pertenece al cantón elegido.');
    ($this->api)()->patchJson('/api/v1/condominio', ['provincia_codigo' => '17'])
        ->assertStatus(422)->assertJsonPath('error.fields.canton_codigo.0', 'Elige el cantón.');
    ($this->api)()->patchJson('/api/v1/condominio', ['latitud' => 40.4, 'longitud' => -3.7])
        ->assertStatus(422)->assertJsonPath('error.fields.latitud.0', 'La ubicación debe estar en Ecuador.');
    ($this->api)()->patchJson('/api/v1/condominio', ['latitud' => -0.18])
        ->assertStatus(422)->assertJsonPath('error.fields.longitud.0', 'Marca la ubicación en el mapa.');
});

it('guarda los colores en mayúscula, conserva el logo y permite restablecerlos', function () {
    ($this->subir)('claro')->assertOk();

    ($this->api)()->patchJson('/api/v1/condominio', ['color_primario' => '#1f4c9a', 'color_acento' => '#f0b35a'])
        ->assertOk()
        ->assertJsonPath('data.marca.color_primario', '#1F4C9A')
        ->assertJsonPath('data.marca.color_acento', '#F0B35A')
        ->assertJsonPath('data.marca.logo_claro_url', fn ($url) => is_string($url));

    ($this->api)()->patchJson('/api/v1/condominio', ['color_acento' => null])->assertOk()
        ->assertJsonPath('data.marca.color_primario', '#1F4C9A')
        ->assertJsonPath('data.marca.color_acento', null)
        ->assertJsonPath('data.marca.logo_claro_url', fn ($url) => is_string($url));

    foreach (['azul', '#12345', '#GGGGGG', 'rgb(1,2,3)'] as $malo) {
        ($this->api)()->patchJson('/api/v1/condominio', ['color_primario' => $malo])
            ->assertStatus(422)->assertJsonPath('error.fields.color_primario.0', 'Escribe un color hex válido, por ejemplo #1F4C9A.');
    }
});

it('sube el logo a S3 bajo el condominio, lo reemplaza y borra el anterior', function () {
    $r = ($this->subir)('claro')->assertOk()->assertJsonPath('message', 'Logo guardado.');
    $primero = ($this->condominio->fresh()->marca)['logo_claro'];

    expect($primero)->toStartWith("condominios/{$this->condominio->id}/marca/")->and($primero)->toEndWith('.png');
    Storage::disk('local')->assertExists($primero);
    // La ruta interna nunca sale de la API
    expect($r->getContent())->not->toContain($primero)->and($r->json('data.marca.logo_claro_url'))->toContain("/marca/{$this->condominio->codigo}/logo/claro");

    ($this->subir)('claro')->assertOk();
    $segundo = ($this->condominio->fresh()->marca)['logo_claro'];

    expect($segundo)->not->toBe($primero);
    Storage::disk('local')->assertMissing($primero);
    Storage::disk('local')->assertExists($segundo);
});

it('el logo se ve sin sesión, con sus cabeceras, y solo si existe', function () {
    ($this->subir)('oscuro')->assertOk();
    cambiarDeUsuario();

    $r = $this->get("/api/v1/marca/{$this->condominio->codigo}/logo/oscuro")->assertOk();
    expect($r->headers->get('content-type'))->toBe('image/png')
        ->and($r->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($r->headers->get('content-security-policy'))->toContain('sandbox');

    $this->get("/api/v1/marca/{$this->condominio->codigo}/logo/claro")->assertNotFound();
    $this->get("/api/v1/marca/{$this->condominio->codigo}/logo/otro")->assertNotFound();
    $this->get('/api/v1/marca/SF-9999/logo/oscuro')->assertNotFound();
});

it('el logo de un condominio no sale con el código de otro', function () {
    ($this->subir)('claro')->assertOk();
    $otro = Condominio::factory()->create();
    cambiarDeUsuario();

    $this->get("/api/v1/marca/{$otro->codigo}/logo/claro")->assertNotFound();
});

it('rechaza logos que no son PNG, pesados o de tamaño raro', function () {
    foreach ([
        'jpg' => UploadedFile::fake()->image('logo.jpg', 200, 200),
        'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'png falso' => UploadedFile::fake()->createWithContent('logo.png', 'no soy una imagen'),
        'pesado' => UploadedFile::fake()->image('logo.png', 200, 200)->size(2048),
        'pequeño' => UploadedFile::fake()->image('logo.png', 10, 10),
        'enorme' => UploadedFile::fake()->image('logo.png', 3000, 3000),
    ] as $motivo => $archivo) {
        ($this->subir)('claro', $archivo)->assertStatus(422);
    }

    expect($this->condominio->fresh()->marca)->toBeNull();
});

it('quita el logo y borra el archivo', function () {
    ($this->subir)('claro')->assertOk();
    $ruta = ($this->condominio->fresh()->marca)['logo_claro'];

    ($this->api)()->deleteJson('/api/v1/condominio/logo/claro')->assertOk()->assertJsonPath('data.marca.logo_claro_url', null);

    Storage::disk('local')->assertMissing($ruta);
    ($this->api)()->postJson('/api/v1/condominio/logo/rojo')->assertNotFound();
});

it('el administrador ve los logos y colores en /auth/me', function () {
    ($this->subir)('claro')->assertOk();
    ($this->api)()->patchJson('/api/v1/condominio', ['color_primario' => '#1F4C9A'])->assertOk();

    $marca = ($this->api)()->getJson('/api/v1/auth/me')->json('data.condominios.0.marca');

    expect($marca['color_primario'])->toBe('#1F4C9A')
        ->and($marca['logo_url'])->toContain("/api/v1/marca/{$this->condominio->codigo}/logo/claro")
        ->and(json_encode($marca))->not->toContain('condominios/'.$this->condominio->id);
});

it('solo quien edita el condominio puede ver y cambiar sus datos', function () {
    foreach ([Rol::Guardia, Rol::Residente] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/condominio')->assertForbidden();
        $api()->patchJson('/api/v1/condominio', ['nombre' => 'Hackeado'])->assertForbidden();
        $api()->post('/api/v1/condominio/logo/claro', ['archivo' => ($this->png)()], ['Accept' => 'application/json'])->assertForbidden();
        $api()->deleteJson('/api/v1/condominio/logo/claro')->assertForbidden();
    }

    expect($this->condominio->fresh()->nombre)->toBe('Conjunto Jardines del Valle');
});

it('cada administrador solo cambia su propio condominio', function () {
    $otro = Condominio::factory()->create(['nombre' => 'Otro Conjunto']);

    ($this->api)()->patchJson('/api/v1/condominio', ['nombre' => 'Cambiado'])->assertOk();

    expect($otro->fresh()->nombre)->toBe('Otro Conjunto')->and($this->condominio->fresh()->nombre)->toBe('Cambiado');
});
