<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create();
    [, $this->tokenAdmin] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '5.0000']);
    [, $this->tokenB, $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '5.0000']);
    [, $this->tokenInq] = residenteDe($this->condominio, 'A-103', 'inquilino', ['alicuota' => '5.0000']);
    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenAdmin)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };

    $this->asamblea = ($this->como)()->postJson('/api/v1/asambleas', [
        'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial',
        'fecha_hora' => hoyLocal()->addDays(45)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal',
    ])->assertCreated()->json('data.id');
    ($this->como)()->postJson("/api/v1/asambleas/{$this->asamblea}/puntos", ['titulo' => 'Informe', 'tipo' => 'informativo'])->assertCreated();

    $this->convocar = fn () => ($this->como)()->postJson("/api/v1/asambleas/{$this->asamblea}/convocar")->assertOk();
    $this->dar = fn (string $token, array $extra = [], ?int $unidad = null) => ($this->como)($token)->post("/api/v1/asambleas/{$this->asamblea}/poderes", $extra + [
        'unidad_id' => $unidad ?? $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => 'Marta Vera Cano', 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json']);
});

it('el propietario sube el poder de su unidad y queda pendiente', function () {
    ($this->convocar)();

    $r = ($this->dar)($this->tokenA)->assertCreated();

    expect($r->json('data'))->toMatchArray(['estado' => 'pendiente', 'unidad_codigo' => 'A-101', 'tipo' => 'carta', 'representante_nombre' => 'Marta Vera Cano', 'representante_registrado' => false, 'unidad_en_mora' => false])
        ->and($r->json('data.representante_documento'))->toBe('17••••••65')->and($r->json('data.documento_url'))->not->toBeNull();
    // La cédula se guarda cifrada
    expect(DB::table('asamblea_poderes')->value('representante_documento'))->not->toBe('1710034065');
});

it('solo en una asamblea convocada y hasta que se instala', function () {
    ($this->dar)($this->tokenA)->assertStatus(409)->assertJsonPath('error.code', 'PODERES_CERRADOS');

    ($this->convocar)();
    ($this->dar)($this->tokenA)->assertCreated();
    enCondominio($this->condominio, fn () => Asamblea::whereKey($this->asamblea)->update(['estado' => 'instalada']));
    ($this->dar)($this->tokenB, [], $this->unidadB->id)->assertStatus(409)->assertJsonPath('error.code', 'PODERES_CERRADOS');
});

it('un propietario solo da poder por sus unidades y un inquilino no puede', function () {
    ($this->convocar)();

    ($this->dar)($this->tokenA, [], $this->unidadB->id)->assertStatus(403)->assertJsonPath('error.code', 'NO_ES_PROPIETARIO');
    // La unidad del inquilino no tiene propietario: no entra al padrón
    ($this->dar)($this->tokenInq, [], enCondominio($this->condominio, fn () => Ocupante::where('relacion', 'inquilino')->value('unidad_id')))->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_SIN_DERECHO');
});

it('la administración puede cargar el poder en nombre del propietario', function () {
    ($this->convocar)();

    ($this->dar)($this->tokenAdmin, [], $this->unidadB->id)->assertCreated()->assertJsonPath('data.unidad_codigo', 'A-102');
});

it('valida los datos: cédula del representante, tipo y PDF', function () {
    ($this->convocar)();

    ($this->dar)($this->tokenA, ['representante_documento' => '1712345678'])->assertStatus(422)->assertJsonPath('error.fields.representante_documento.0', 'La cédula no es válida.');
    ($this->dar)($this->tokenA, ['tipo' => 'verbal'])->assertStatus(422);
    ($this->dar)($this->tokenA, ['representante_nombre' => 'M'])->assertStatus(422);
    ($this->dar)($this->tokenA, ['archivo' => UploadedFile::fake()->image('poder.png')])->assertStatus(422);
    ($this->dar)($this->tokenA, ['representante_tipo_documento' => 'pasaporte', 'representante_documento' => 'AB123456'])->assertCreated();
});

it('una unidad tiene un solo poder vigente; revocado, se puede cambiar de representante', function () {
    ($this->convocar)();
    $id = ($this->dar)($this->tokenA)->assertCreated()->json('data.id');

    ($this->dar)($this->tokenA)->assertStatus(409)->assertJsonPath('error.code', 'PODER_YA_EXISTE');
    ($this->como)($this->tokenA)->deleteJson("/api/v1/poderes/{$id}")->assertOk()->assertJsonPath('data.estado', 'revocado');
    ($this->dar)($this->tokenA, ['representante_nombre' => 'Luis Mora Peña', 'representante_documento' => '1712345675'])->assertCreated();
    ($this->como)($this->tokenA)->deleteJson("/api/v1/poderes/{$id}")->assertStatus(409)->assertJsonPath('error.code', 'PODER_NO_VIGENTE');
});

it('nadie retira el poder de una unidad ajena', function () {
    ($this->convocar)();
    $id = ($this->dar)($this->tokenA)->assertCreated()->json('data.id');

    ($this->como)($this->tokenB)->deleteJson("/api/v1/poderes/{$id}")->assertStatus(403)->assertJsonPath('error.code', 'NO_ES_PROPIETARIO');
    ($this->como)()->deleteJson("/api/v1/poderes/{$id}")->assertOk();
});

it('la administración valida o rechaza con motivo, una sola vez', function () {
    ($this->convocar)();
    $a = ($this->dar)($this->tokenA)->assertCreated()->json('data.id');
    $b = ($this->dar)($this->tokenB, [], $this->unidadB->id)->assertCreated()->json('data.id');

    ($this->como)($this->tokenA)->postJson("/api/v1/poderes/{$a}/validar")->assertForbidden();
    ($this->como)()->postJson("/api/v1/poderes/{$a}/validar")->assertOk()->assertJsonPath('data.estado', 'validado')->assertJsonPath('data.validado_por', fn ($v) => $v !== null);
    ($this->como)()->postJson("/api/v1/poderes/{$a}/rechazar", ['motivo' => 'Ya no'])->assertStatus(409)->assertJsonPath('error.code', 'PODER_NO_PENDIENTE');

    ($this->como)()->postJson("/api/v1/poderes/{$b}/rechazar", ['motivo' => ''])->assertStatus(422);
    ($this->como)()->postJson("/api/v1/poderes/{$b}/rechazar", ['motivo' => 'La firma no coincide'])->assertOk()->assertJsonPath('data.estado', 'rechazado')->assertJsonPath('data.motivo_rechazo', 'La firma no coincide');
    // Rechazado, el propietario puede subir otro
    ($this->dar)($this->tokenB, [], $this->unidadB->id)->assertCreated();
});

it('un representante en mora no puede representar a otro propietario', function () {
    ($this->convocar)();
    // La representante es la propietaria de A-102, que debe cuotas
    $cedula = enCondominio($this->condominio, fn () => Persona::factory()->create(['tipo_documento' => 'cedula', 'documento' => '1710034065']));
    enCondominio($this->condominio, fn () => Ocupante::where('unidad_id', $this->unidadB->id)->update(['persona_id' => $cedula->id]));
    cuotaDe($this->condominio, $this->unidadB, 3);

    $id = ($this->dar)($this->tokenA)->assertCreated()->assertJsonPath('data.representante_registrado', true)->json('data.id');

    ($this->como)()->postJson("/api/v1/poderes/{$id}/validar")->assertStatus(422)->assertJsonPath('error.code', 'REPRESENTANTE_EN_MORA');
});

it('el propietario no se representa a sí mismo', function () {
    ($this->convocar)();
    $propietario = enCondominio($this->condominio, fn () => Persona::factory()->create(['tipo_documento' => 'cedula', 'documento' => '1710034065']));
    enCondominio($this->condominio, fn () => Ocupante::where('unidad_id', $this->unidadA->id)->update(['persona_id' => $propietario->id]));

    ($this->dar)($this->tokenAdmin)->assertStatus(422)->assertJsonPath('error.code', 'REPRESENTANTE_ES_PROPIETARIO');
});

it('la administración lista los poderes con sus totales y marca la mora de la unidad', function () {
    ($this->convocar)();
    $a = ($this->dar)($this->tokenA)->assertCreated()->json('data.id');
    ($this->dar)($this->tokenB, [], $this->unidadB->id)->assertCreated();
    ($this->como)()->postJson("/api/v1/poderes/{$a}/validar")->assertOk();
    cuotaDe($this->condominio, $this->unidadB, 3);

    $r = ($this->como)()->getJson("/api/v1/asambleas/{$this->asamblea}/poderes")->assertOk();

    expect($r->json('data'))->toMatchArray(['pendientes' => 1, 'validados' => 1, 'rechazados' => 0])
        ->and($r->json('data.poderes'))->toHaveCount(2)->and($r->json('data.poderes.1.unidad_en_mora'))->toBeTrue();
    ($this->como)($this->tokenA)->getJson("/api/v1/asambleas/{$this->asamblea}/poderes")->assertForbidden();
});

it('mis asambleas: el propietario ve sus unidades con el poder de cada una; el inquilino ninguna', function () {
    ($this->convocar)();
    ($this->dar)($this->tokenA)->assertCreated();

    $r = ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data');

    expect($r)->toHaveCount(1)->and($r[0])->toMatchArray(['titulo' => 'Asamblea ordinaria 2026', 'estado' => 'convocada', 'puede_dar_poder' => true])
        ->and($r[0]['unidades'])->toHaveCount(1)->and($r[0]['unidades'][0]['codigo'])->toBe('A-101')->and($r[0]['unidades'][0]['poder']['estado'])->toBe('pendiente');
    $inq = ($this->como)($this->tokenInq)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data');
    expect($inq[0]['unidades'])->toBe([])->and($inq[0]['puede_dar_poder'])->toBeFalse();
    ($this->como)($this->tokenAdmin)->getJson('/api/v1/mis-asambleas')->assertOk();   // el administrador tiene todos los permisos
});

it('un borrador no aparece en mis asambleas', function () {
    ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->assertOk()->assertJsonCount(0, 'data');
});

it('el poder de un condominio no se ve en otro', function () {
    ($this->convocar)();
    $id = ($this->dar)($this->tokenA)->assertCreated()->json('data.id');
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Administrador);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->postJson("/api/v1/poderes/{$id}/validar")->assertNotFound();
    expect(enCondominio($this->condominio, fn () => AsambleaPoder::find($id)->estado))->toBe('pendiente');
});
