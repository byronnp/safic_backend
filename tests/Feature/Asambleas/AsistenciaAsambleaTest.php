<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaAsistencia;
use App\Modules\Asambleas\Models\ConfiguracionAsambleas;
use App\Modules\Plataforma\Models\Condominio;
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
    [, $this->tokenSecretario] = usuarioConToken($this->condominio, Rol::Secretario);
    [, $this->tokenGuardia] = usuarioConToken($this->condominio, Rol::Guardia);
    // Alícuotas que suman 10: la mitad exacta (5) no alcanza, 6 sí
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '4.0000']);
    [, $this->tokenB, $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '1.0000']);
    [, $this->tokenC, $this->unidadC] = residenteDe($this->condominio, 'A-103', 'propietario', ['alicuota' => '5.0000']);
    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenSecretario)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->crear = function (string $modalidad = 'presencial') {
        $id = ($this->como)($this->tokenAdmin)->postJson('/api/v1/asambleas', [
            'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => $modalidad,
            'fecha_hora' => hoyLocal()->addDays(45)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal', 'enlace_video' => $modalidad === 'presencial' ? null : 'https://meet.example.com/a',
        ])->assertCreated()->json('data.id');
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", ['titulo' => 'Informe', 'tipo' => 'informativo'])->assertCreated();
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();

        return $id;
    };
    $this->marcar = fn (int $id, int $unidad, ?string $token = null) => ($this->como)($token)->postJson("/api/v1/asambleas/{$id}/asistencia", ['unidad_id' => $unidad]);
    $this->quorum = fn (int $id) => ($this->como)()->getJson("/api/v1/asambleas/{$id}/quorum")->assertOk()->json('data');
});

it('el secretario abre el registro; antes de abrirlo no se marca a nadie', function () {
    $id = ($this->crear)();

    ($this->marcar)($id, $this->unidadA->id)->assertStatus(409)->assertJsonPath('error.code', 'REGISTRO_CERRADO');
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk()->assertJsonPath('data.registro_abierto', true);
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();   // abrirlo otra vez no daña
    expect(enCondominio($this->condominio, fn () => Asamblea::find($id)->estado))->toBe('en_registro');
});

it('solo quien registra abre el registro y marca en mesa', function () {
    $id = ($this->crear)();

    foreach ([$this->tokenGuardia, $this->tokenA] as $token) {
        ($this->como)($token)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertForbidden();
        ($this->como)($token)->getJson("/api/v1/asambleas/{$id}/asistencia")->assertForbidden();
    }
    ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    ($this->marcar)($id, $this->unidadA->id, $this->tokenGuardia)->assertForbidden();
    // Un propietario no marca a otra unidad en mesa
    ($this->marcar)($id, $this->unidadB->id, $this->tokenA)->assertStatus(403)->assertJsonPath('error.code', 'SIN_PERMISO');
});

it('el quórum suma alícuotas y necesita más de la mitad', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->marcar)($id, $this->unidadC->id)->assertOk();   // 5 de 10: la mitad exacta no alcanza
    $q = ($this->quorum)($id);
    expect($q)->toMatchArray(['unidades_total' => 3, 'unidades_presentes' => 1, 'alicuota_total' => '10.00', 'alicuota_presente' => '5.00', 'porcentaje' => '50.00', 'hay_quorum' => false]);

    ($this->marcar)($id, $this->unidadB->id)->assertOk()->assertJsonPath('data.hay_quorum', true)->assertJsonPath('data.alicuota_presente', '6.00');
    expect(($this->quorum)($id)['por_forma'])->toBe(['presencial' => 2, 'virtual' => 0, 'poder' => 0]);
});

it('la salida baja el quórum y quien vuelve a entrar queda en otra fila', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    ($this->marcar)($id, $this->unidadC->id)->assertOk();
    ($this->marcar)($id, $this->unidadA->id)->assertOk()->assertJsonPath('data.hay_quorum', true);

    ($this->como)()->postJson("/api/v1/asambleas/{$id}/salida", ['unidad_id' => $this->unidadA->id])->assertOk()->assertJsonPath('data.hay_quorum', false);
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/salida", ['unidad_id' => $this->unidadA->id])->assertStatus(409)->assertJsonPath('error.code', 'NO_REGISTRADA');
    ($this->marcar)($id, $this->unidadA->id)->assertOk()->assertJsonPath('data.hay_quorum', true);
    expect(enCondominio($this->condominio, fn () => AsambleaAsistencia::where('unidad_id', $this->unidadA->id)->count()))->toBe(2);
});

it('una unidad no se marca dos veces', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    ($this->marcar)($id, $this->unidadA->id)->assertOk();

    ($this->marcar)($id, $this->unidadA->id)->assertStatus(409)->assertJsonPath('error.code', 'YA_REGISTRADA');
});

it('una unidad sin propietario o sin alícuota no entra', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    [, , $inquilina] = residenteDe($this->condominio, 'B-1', 'inquilino', ['alicuota' => '1.0000']);

    ($this->marcar)($id, $inquilina->id)->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_SIN_DERECHO');
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/asistencia", ['unidad_id' => $this->unidadA->id, 'asistente_persona_id' => 999999])->assertStatus(422)->assertJsonPath('error.code', 'NO_ES_PROPIETARIO');
});

it('una unidad con poder validado entra con su representante, no con el propietario', function () {
    $id = ($this->crear)();
    $poder = ($this->como)($this->tokenA)->post("/api/v1/asambleas/{$id}/poderes", [
        'unidad_id' => $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => 'Marta Vera Cano', 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    ($this->como)($this->tokenAdmin)->postJson("/api/v1/poderes/{$poder}/validar")->assertOk();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->marcar)($id, $this->unidadA->id)->assertOk()->assertJsonPath('data.por_forma.poder', 1);
    $fila = collect(($this->como)()->getJson("/api/v1/asambleas/{$id}/asistencia")->assertOk()->json('data.unidades'))->firstWhere('codigo', 'A-101');
    expect($fila['presente'])->toMatchArray(['asistente' => 'Marta Vera Cano', 'forma' => 'poder', 'canal' => 'mesa'])->and($fila['poder']['representante_nombre'])->toBe('Marta Vera Cano');
});

it('si llega el propietario, quien registra retira el poder y lo marca', function () {
    $id = ($this->crear)();
    $poder = ($this->como)($this->tokenA)->post("/api/v1/asambleas/{$id}/poderes", [
        'unidad_id' => $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => 'Marta Vera Cano', 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    ($this->como)($this->tokenAdmin)->postJson("/api/v1/poderes/{$poder}/validar")->assertOk();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->como)()->deleteJson("/api/v1/poderes/{$poder}")->assertOk();
    ($this->marcar)($id, $this->unidadA->id)->assertOk()->assertJsonPath('data.por_forma.presencial', 1);
});

it('la unidad en mora suma al quórum por defecto; si el condominio lo cambia, sale de la base', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    cuotaDe($this->condominio, $this->unidadC, 3);
    ($this->marcar)($id, $this->unidadC->id)->assertOk();

    expect(($this->quorum)($id))->toMatchArray(['alicuota_total' => '10.00', 'alicuota_presente' => '5.00', 'mora_cuenta_para_quorum' => true]);

    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['mora_cuenta_para_quorum' => false]));
    expect(($this->quorum)($id))->toMatchArray(['alicuota_total' => '5.00', 'alicuota_presente' => '0.00', 'unidades_total' => 2, 'hay_quorum' => false]);
});

it('la segunda convocatoria se abre a los 60 minutos de la hora de la asamblea', function () {
    $id = ($this->crear)();
    expect(($this->quorum)($id)['segunda_convocatoria'])->toMatchArray(['abierta' => false])->and(($this->quorum)($id)['segunda_convocatoria']['desde'])->toEndWith('20:00');

    enCondominio($this->condominio, fn () => Asamblea::whereKey($id)->update(['fecha_hora' => now()->subMinutes(61)]));
    expect(($this->quorum)($id)['segunda_convocatoria'])->toMatchArray(['abierta' => true, 'minutos_faltan' => 0]);
});

it('«estoy presente» solo en asambleas virtuales o mixtas', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->como)($this->tokenA)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertStatus(409)->assertJsonPath('error.code', 'SOLO_EN_MESA');
});

it('en una asamblea virtual el propietario marca su presencia, la mantiene viva y sale', function () {
    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['permite_virtual' => true]));
    $id = ($this->crear)('virtual');
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->como)($this->tokenC)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertOk()->assertJsonPath('data.por_forma.virtual', 1);
    ($this->como)($this->tokenC)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertOk();   // repetirlo no duplica
    expect(enCondominio($this->condominio, fn () => AsambleaAsistencia::count()))->toBe(1);

    $mis = ($this->como)($this->tokenC)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data.0');
    expect($mis)->toMatchArray(['registro_abierto' => true, 'puede_marcar_presencia' => true, 'presente' => true]);

    // Sin actividad por 10 minutos deja de contar
    enCondominio($this->condominio, fn () => AsambleaAsistencia::query()->update(['ultima_actividad_en' => now()->subMinutes(11)]));
    expect(($this->quorum)($id)['unidades_presentes'])->toBe(0);
    ($this->como)($this->tokenC)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertOk();
    expect(($this->quorum)($id)['unidades_presentes'])->toBe(1);

    ($this->como)($this->tokenC)->postJson("/api/v1/asambleas/{$id}/salida")->assertOk()->assertJsonPath('data.unidades_presentes', 0);
    ($this->como)($this->tokenC)->postJson("/api/v1/asambleas/{$id}/salida")->assertStatus(409);
});

it('un inquilino no puede marcar presencia por la app', function () {
    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['permite_virtual' => true]));
    $id = ($this->crear)('virtual');
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    [, $tokenInq] = residenteDe($this->condominio, 'B-1', 'inquilino', ['alicuota' => '1.0000']);

    ($this->como)($tokenInq)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertStatus(403)->assertJsonPath('error.code', 'SIN_UNIDADES');
});

it('el representante de un poder marca su presencia virtual por la unidad que representa', function () {
    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['permite_virtual' => true]));
    $id = ($this->crear)('virtual');
    [$userR, $tokenR] = residenteDe($this->condominio, 'R-1', 'inquilino');
    // La representante es una persona del condominio con cédula válida: así su poder la reconoce
    $persona = enCondominio($this->condominio, function () use ($userR) {
        $p = Persona::where('user_id', $userR->id)->first();
        $p->update(['tipo_documento' => 'cedula', 'documento' => '1710034065']);

        return $p;
    });
    $poder = ($this->como)($this->tokenA)->post("/api/v1/asambleas/{$id}/poderes", [
        'unidad_id' => $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => $persona->nombreCompleto(), 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.representante_registrado', true);
    ($this->como)($this->tokenAdmin)->postJson('/api/v1/poderes/'.$poder->json('data.id').'/validar')->assertOk();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

    ($this->como)($tokenR)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertOk()->assertJsonPath('data.por_forma.poder', 1);
    // El propietario que dio poder ya no puede marcarse a sí mismo
    ($this->como)($this->tokenA)->postJson("/api/v1/asambleas/{$id}/asistencia")->assertStatus(403)->assertJsonPath('error.code', 'SIN_UNIDADES');
});

it('la asistencia de un condominio no se ve en otro', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
    ($this->marcar)($id, $this->unidadA->id)->assertOk();
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Secretario);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson("/api/v1/asambleas/{$id}/quorum")->assertNotFound();
});

it('el secretario ve la lista de asambleas para elegir cuál registrar', function () {
    ($this->crear)();

    ($this->como)()->getJson('/api/v1/asambleas')->assertOk()->assertJsonCount(1, 'data');
    ($this->como)($this->tokenGuardia)->getJson('/api/v1/asambleas')->assertForbidden();
});
