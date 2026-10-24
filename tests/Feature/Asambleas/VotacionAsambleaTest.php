<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\ConfiguracionAsambleas;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Models\Voto;
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
    [, $this->tokenPresidente] = usuarioConToken($this->condominio, Rol::Presidente);
    [, $this->tokenSecretario] = usuarioConToken($this->condominio, Rol::Secretario);
    // A 4 + B 1 + C 5 votan; D (2) está en mora y no vota: el total es 12
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '4.0000']);
    [, $this->tokenB, $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '1.0000']);
    [, $this->tokenC, $this->unidadC] = residenteDe($this->condominio, 'A-103', 'propietario', ['alicuota' => '5.0000']);
    [, $this->tokenD, $this->unidadD] = residenteDe($this->condominio, 'A-104', 'propietario', ['alicuota' => '2.0000']);
    [, $this->tokenInq] = residenteDe($this->condominio, 'B-1', 'inquilino', ['alicuota' => '1.0000']);
    cuotaDe($this->condominio, $this->unidadD, 3);

    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenPresidente)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->id = (function () {
        $id = ($this->como)($this->tokenAdmin)->postJson('/api/v1/asambleas', [
            'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial',
            'fecha_hora' => hoyLocal()->addDays(45)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal',
        ])->assertCreated()->json('data.id');
        $punto = fn (array $d) => ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", $d)->assertCreated()->json('data.puntos');
        $punto(['titulo' => 'Informe', 'tipo' => 'informativo']);
        $punto(['titulo' => 'Cuota extraordinaria', 'tipo' => 'votacion', 'mayoria' => 'simple']);
        $punto(['titulo' => 'Segunda propuesta', 'tipo' => 'votacion', 'mayoria' => 'simple']);
        $punto(['titulo' => 'Reforma del reglamento', 'tipo' => 'reforma_reglamento', 'umbral_pct' => 66.67]);
        $punto(['titulo' => 'Elección de directiva', 'tipo' => 'eleccion', 'mayoria' => 'simple']);
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
        ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

        return $id;
    })();
    $this->punto = fn (string $titulo) => enCondominio($this->condominio, fn () => AsambleaPunto::where('asamblea_id', $this->id)->where('titulo', $titulo)->value('id'));
    $this->marcar = fn (int $unidad) => ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/asistencia", ['unidad_id' => $unidad])->assertOk();
    $this->instalar = function () {
        foreach ([$this->unidadA, $this->unidadB, $this->unidadC, $this->unidadD] as $u) {
            ($this->marcar)($u->id);
        }
        ($this->como)()->postJson("/api/v1/asambleas/{$this->id}/instalar", ['convocatoria' => 1])->assertOk();
    };
    $this->abrir = fn (string $titulo = 'Cuota extraordinaria', array $d = []) => ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)($titulo).'/votacion/abrir', $d);
    $this->votar = fn (int $votacion, string $token, string $opcion, ?int $unidad = null) => ($this->como)($token)->postJson("/api/v1/votaciones/{$votacion}/votos", ['opcion' => $opcion] + ($unidad === null ? [] : ['unidad_id' => $unidad]));
    $this->cerrar = fn (int $votacion) => ($this->como)()->postJson("/api/v1/votaciones/{$votacion}/cerrar")->assertOk()->json('data');
});

it('el presidente abre la votación de un punto de una asamblea instalada', function () {
    ($this->abrir)()->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_INSTALADA');
    ($this->instalar)();

    $r = ($this->abrir)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['estado' => 'abierta', 'titulo' => 'Cuota extraordinaria', 'presentes_con_voto' => 3, 'presentes_en_mora' => 1, 'votos_emitidos' => 0, 'resultado' => null])
        ->and($r->json('data.pendientes'))->toHaveCount(3);
    expect(enCondominio($this->condominio, fn () => Asamblea::find($this->id)->estado))->toBe('en_votacion');
});

it('solo una votación a la vez; un punto se vota una vez; informativos y elecciones no se votan así', function () {
    ($this->instalar)();
    ($this->abrir)('Informe')->assertStatus(422)->assertJsonPath('error.code', 'PUNTO_INFORMATIVO');
    ($this->abrir)('Elección de directiva')->assertStatus(422)->assertJsonPath('error.code', 'CARGO_REQUERIDO');

    $id = ($this->abrir)()->assertCreated()->json('data.id');
    ($this->abrir)('Segunda propuesta')->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_ABIERTA');
    ($this->cerrar)($id);
    ($this->abrir)()->assertStatus(409)->assertJsonPath('error.code', 'PUNTO_YA_VOTADO');
    ($this->abrir)('Segunda propuesta')->assertCreated();
});

it('solo el presidente abre y cierra; el administrador y el secretario no', function () {
    ($this->instalar)();
    $punto = ($this->punto)('Cuota extraordinaria');

    foreach ([$this->tokenAdmin, $this->tokenSecretario, $this->tokenA] as $token) {
        ($this->como)($token)->postJson("/api/v1/puntos/{$punto}/votacion/abrir")->assertForbidden();
    }
    $id = ($this->abrir)()->assertCreated()->json('data.id');
    foreach ([$this->tokenAdmin, $this->tokenSecretario, $this->tokenA] as $token) {
        ($this->como)($token)->postJson("/api/v1/votaciones/{$id}/cerrar")->assertForbidden();
    }
});

it('un propietario presente y sin mora vota; no puede votar dos veces y se dice quién votó', function () {
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->json('data.id');

    $r = ($this->votar)($id, $this->tokenA, 'si')->assertCreated();
    expect($r->json('data.unidades.0'))->toMatchArray(['codigo' => 'A-101', 'estado' => 'registrado']);

    $dos = ($this->votar)($id, $this->tokenA, 'no')->assertStatus(409)->assertJsonPath('error.code', 'YA_VOTO');
    expect($dos->json('error.message'))->toContain('A-101')->toContain('ya votó');
    expect(enCondominio($this->condominio, fn () => Voto::where('votacion_id', $id)->count()))->toBe(1);
});

it('no vota la unidad en mora, la ausente, ni quien no es propietario', function () {
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->json('data.id');

    ($this->votar)($id, $this->tokenD, 'si')->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_EN_MORA');
    ($this->votar)($id, $this->tokenInq, 'si')->assertStatus(403)->assertJsonPath('error.code', 'SIN_DERECHO_A_VOTO');
    ($this->votar)($id, $this->tokenA, 'quizá')->assertStatus(422);
    // Un propietario no vota por la unidad de otro
    ($this->votar)($id, $this->tokenA, 'si', $this->unidadB->id)->assertStatus(403)->assertJsonPath('error.code', 'NO_VOTA_POR_ESA_UNIDAD');

    // Sale de la sala: ya no vota
    ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/salida", ['unidad_id' => $this->unidadB->id])->assertOk();
    ($this->votar)($id, $this->tokenB, 'si')->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_AUSENTE');
});

it('el secretario registra en mesa el voto de una unidad presente', function () {
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->json('data.id');

    ($this->votar)($id, $this->tokenSecretario, 'no', $this->unidadB->id)->assertCreated();
    ($this->votar)($id, $this->tokenSecretario, 'no', $this->unidadD->id)->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_EN_MORA');
    ($this->votar)($id, $this->tokenSecretario, 'no', $this->unidadB->id)->assertStatus(409)->assertJsonPath('error.code', 'YA_VOTO');

    $voto = enCondominio($this->condominio, fn () => Voto::where('votacion_id', $id)->first());
    expect($voto->canal)->toBe('mesa')->and($voto->emitido_por)->not->toBeNull();
});

it('el representante con poder validado vota por la unidad y el propietario ya no', function () {
    [$userR, $tokenR] = residenteDe($this->condominio, 'R-1', 'inquilino');
    $persona = enCondominio($this->condominio, function () use ($userR) {
        $p = Persona::where('user_id', $userR->id)->first();
        $p->update(['tipo_documento' => 'cedula', 'documento' => '1710034065']);

        return $p;
    });
    $poder = ($this->como)($this->tokenA)->post("/api/v1/asambleas/{$this->id}/poderes", [
        'unidad_id' => $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => $persona->nombreCompleto(), 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    ($this->como)($this->tokenAdmin)->postJson("/api/v1/poderes/{$poder}/validar")->assertOk();
    ($this->instalar)();   // la unidad A entra por su representante
    $id = ($this->abrir)()->assertCreated()->json('data.id');

    ($this->votar)($id, $this->tokenA, 'si')->assertStatus(403)->assertJsonPath('error.code', 'SIN_DERECHO_A_VOTO');
    ($this->votar)($id, $tokenR, 'si')->assertCreated()->assertJsonPath('data.unidades.0.codigo', 'A-101');
});

it('al cerrar cuenta por alícuota: más de la mitad de las presentes con derecho a voto aprueba', function () {
    ($this->instalar)();
    // Presentes con derecho: A 4 + B 1 + C 5 = 10 (D está en mora). Sí = 4 no alcanza
    $id = ($this->abrir)()->assertCreated()->json('data.id');
    ($this->votar)($id, $this->tokenA, 'si')->assertCreated();
    ($this->votar)($id, $this->tokenB, 'no')->assertCreated();
    ($this->votar)($id, $this->tokenC, 'abstencion')->assertCreated();

    $r = ($this->cerrar)($id);
    expect($r)->toMatchArray(['estado' => 'cerrada', 'resultado' => 'rechazado'])
        ->and($r['cifras'])->toMatchArray(['base_alicuota' => '10.0000', 'alicuota_si' => '4.0000', 'alicuota_no' => '1.0000', 'alicuota_abstencion' => '5.0000', 'votos_si' => 1, 'pct_si' => '40.00'])
        ->and($r['integridad'])->toMatchArray(['valida' => true, 'votos' => 3]);
    expect(enCondominio($this->condominio, fn () => Asamblea::find($this->id)->estado))->toBe('instalada');

    // Segunda propuesta: A 4 + C 5 = 9 de 10, aprobada aunque B vote en contra o no vote
    $id2 = ($this->abrir)('Segunda propuesta')->assertCreated()->json('data.id');
    ($this->votar)($id2, $this->tokenA, 'si')->assertCreated();
    ($this->votar)($id2, $this->tokenC, 'si')->assertCreated();
    expect(($this->cerrar)($id2)['resultado'])->toBe('aprobado');
});

it('la reforma del reglamento pide el porcentaje sobre el total de alícuotas, incluida la mora', function () {
    ($this->instalar)();
    $id = ($this->abrir)('Reforma del reglamento')->assertCreated()->json('data.id');
    ($this->votar)($id, $this->tokenA, 'si')->assertCreated();   // 4
    ($this->votar)($id, $this->tokenB, 'si')->assertCreated();   // 1
    // 5 de 12 = 41,67 %: no llega a 66,67 %
    $r = ($this->cerrar)($id);
    expect($r['resultado'])->toBe('rechazado')->and($r['cifras']['base_alicuota'])->toBe('12.0000')->and($r['cifras']['pct_si'])->toBe('41.67');

    // Con C también: 10 de 12 = 83,33 %
    enCondominio($this->condominio, fn () => Votacion::query()->delete());
    enCondominio($this->condominio, fn () => Asamblea::whereKey($this->id)->update(['estado' => 'instalada']));
    $id2 = ($this->abrir)('Reforma del reglamento')->assertCreated()->json('data.id');
    foreach ([$this->tokenA, $this->tokenB, $this->tokenC] as $t) {
        ($this->votar)($id2, $t, 'si')->assertCreated();
    }
    expect(($this->cerrar)($id2)['resultado'])->toBe('aprobado');
});

it('cerrada, no se vota; abierta, solo la mesa ve el conteo y los asistentes no', function () {
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->json('data.id');
    ($this->votar)($id, $this->tokenA, 'si')->assertCreated();

    ($this->como)($this->tokenSecretario)->getJson("/api/v1/votaciones/{$id}")->assertOk()->assertJsonPath('data.cifras.votos_si', 1);
    ($this->como)($this->tokenA)->getJson("/api/v1/votaciones/{$id}")->assertForbidden();
    $mis = ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data.0');
    expect($mis['votacion'])->toMatchArray(['titulo' => 'Cuota extraordinaria'])->and($mis['votacion'])->not->toHaveKey('cifras')
        ->and($mis['votacion']['unidades'][0])->toMatchArray(['codigo' => 'A-101', 'puede_votar' => false, 'voto' => 'si'])
        ->and($mis['resultados'])->toBe([]);

    ($this->cerrar)($id);
    ($this->votar)($id, $this->tokenB, 'si')->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_CERRADA');
    $despues = ($this->como)($this->tokenB)->getJson('/api/v1/mis-asambleas')->json('data.0');
    expect($despues['votacion'])->toBeNull()->and($despues['resultados'])->toHaveCount(1)->and($despues['resultados'][0])->toMatchArray(['titulo' => 'Cuota extraordinaria', 'alicuota_si' => '4.0000']);
});

it('si el quórum baja del mínimo, avisa antes de abrir la siguiente votación', function () {
    ($this->instalar)();   // A+B+C+D = 12 de 12
    foreach ([$this->unidadA, $this->unidadC] as $u) {
        ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/salida", ['unidad_id' => $u->id])->assertOk();
    }

    ($this->abrir)()->assertStatus(409)->assertJsonPath('error.code', 'QUORUM_BAJO');
    ($this->abrir)('Cuota extraordinaria', ['continuar_sin_quorum' => true])->assertCreated();
});

it('con voto secreto la auditoría no dice qué votó cada unidad y la cadena de hashes detecta cambios', function () {
    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['voto_secreto' => true]));
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->assertJsonPath('data.secreta', true)->json('data.id');
    ($this->votar)($id, $this->tokenA, 'si')->assertCreated();
    ($this->votar)($id, $this->tokenB, 'no')->assertCreated();

    ($this->como)($this->tokenSecretario)->get("/api/v1/votaciones/{$id}/auditoria.pdf")->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_ABIERTA');
    ($this->cerrar)($id);
    $pdf = ($this->como)($this->tokenSecretario)->get("/api/v1/votaciones/{$id}/auditoria.pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();

    // Alterar un voto rompe la cadena
    enCondominio($this->condominio, fn () => Voto::query()->where('votacion_id', $id)->orderBy('id')->first()->forceFill(['opcion' => 'no'])->save());
    $r = ($this->como)($this->tokenSecretario)->getJson("/api/v1/votaciones/{$id}")->assertOk()->json('data');
    expect($r['integridad'])->toMatchArray(['valida' => false, 'votos' => 0])->and($r['integridad']['primer_voto_alterado'])->not->toBeNull();
});

it('las votaciones de un condominio no se ven en otro', function () {
    ($this->instalar)();
    $id = ($this->abrir)()->assertCreated()->json('data.id');
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Presidente);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->postJson("/api/v1/votaciones/{$id}/cerrar")->assertNotFound();
});
