<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Acta;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Asambleas\Models\Resolucion;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create();
    [, $this->tokenAdmin] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $this->tokenPresidente] = usuarioConToken($this->condominio, Rol::Presidente);
    [, $this->tokenVice] = usuarioConToken($this->condominio, Rol::Vicepresidente);
    [, $this->tokenSecretario] = usuarioConToken($this->condominio, Rol::Secretario);
    [, $this->tokenGuardia] = usuarioConToken($this->condominio, Rol::Guardia);
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '4.0000']);
    [, $this->tokenB, $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '1.0000']);
    [, $this->tokenC, $this->unidadC] = residenteDe($this->condominio, 'A-103', 'propietario', ['alicuota' => '5.0000']);
    [, , $this->unidadD] = residenteDe($this->condominio, 'A-104', 'propietario', ['alicuota' => '2.0000']);
    cuotaDe($this->condominio, $this->unidadD, 3);

    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenPresidente)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    /** Una asamblea instalada con los puntos indicados. */
    $this->crear = function (string $titulo, array $puntos) {
        $id = ($this->como)($this->tokenAdmin)->postJson('/api/v1/asambleas', [
            'titulo' => $titulo, 'tipo' => 'ordinaria', 'modalidad' => 'presencial',
            'fecha_hora' => hoyLocal()->addDays(45)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal',
        ])->assertCreated()->json('data.id');
        foreach ($puntos as $p) {
            ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", $p)->assertCreated();
        }
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
        ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();
        foreach ([$this->unidadA, $this->unidadB, $this->unidadC, $this->unidadD] as $u) {
            ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/asistencia", ['unidad_id' => $u->id])->assertOk();
        }
        ($this->como)()->postJson("/api/v1/asambleas/{$id}/instalar", ['convocatoria' => 1])->assertOk();

        return $id;
    };
    $this->puntos = [
        ['titulo' => 'Informe', 'tipo' => 'informativo'],
        ['titulo' => 'Cuota extraordinaria', 'tipo' => 'votacion', 'mayoria' => 'simple', 'descripcion' => 'Cuota para el tanque de agua.'],
        ['titulo' => 'Reforma del reglamento', 'tipo' => 'reforma_reglamento', 'umbral_pct' => 66.67],
    ];
    $this->id = ($this->crear)('Asamblea ordinaria 2026', $this->puntos);
    $this->punto = fn (string $t, ?int $asamblea = null) => enCondominio($this->condominio, fn () => AsambleaPunto::where('asamblea_id', $asamblea ?? $this->id)->where('titulo', $t)->value('id'));
    /** Vota «Cuota extraordinaria»: A y C a favor (9 de 10: aprobada). */
    $this->votarCuota = function () {
        $v = ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Cuota extraordinaria').'/votacion/abrir')->assertCreated()->json('data.id');
        foreach ([$this->tokenA, $this->tokenC] as $t) {
            ($this->como)($t)->postJson("/api/v1/votaciones/{$v}/votos", ['opcion' => 'si'])->assertCreated();
        }
        ($this->como)()->postJson("/api/v1/votaciones/{$v}/cerrar")->assertOk();
    };
    $this->cerrar = fn (array $d = [], ?string $token = null, ?int $asamblea = null) => ($this->como)($token)->postJson('/api/v1/asambleas/'.($asamblea ?? $this->id).'/cerrar', $d);
    $this->acta = fn (?string $token = null, ?int $asamblea = null) => ($this->como)($token ?? $this->tokenSecretario)->getJson('/api/v1/asambleas/'.($asamblea ?? $this->id).'/acta');
    $this->firmar = fn (int $acta, string $token, array $d = []) => ($this->como)($token)->postJson("/api/v1/actas/{$acta}/firmar", $d);
    $this->cerrarYFirmar = function () {
        ($this->votarCuota)();
        ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
        $id = ($this->acta)()->json('data.id');
        ($this->firmar)($id, $this->tokenSecretario)->assertOk();
        ($this->firmar)($id, $this->tokenPresidente)->assertOk();

        return $id;
    };
});

it('al cerrar, los puntos sin votar piden confirmación y constan sin tratar', function () {
    ($this->votarCuota)();

    ($this->cerrar)()->assertStatus(409)->assertJsonPath('error.code', 'PUNTOS_PENDIENTES');
    $r = ($this->cerrar)(['confirmar_pendientes' => true])->assertOk()->json('data');

    expect($r)->toMatchArray(['numero' => 1, 'estado' => 'pendiente', 'resoluciones' => 1, 'puede_firmar' => true, 'tu_firma' => 'presidente']);
    $estados = enCondominio($this->condominio, fn () => collect(Acta::first()->contenido['puntos'])->pluck('estado', 'titulo')->all());
    expect($estados)->toBe(['Informe' => 'tratado', 'Cuota extraordinaria' => 'votado', 'Reforma del reglamento' => 'no_tratado']);
    expect(enCondominio($this->condominio, fn () => Asamblea::find($this->id)->only(['estado', 'cerrada_por'])))->toMatchArray(['estado' => 'cerrada']);
});

it('solo el presidente cierra, con la asamblea instalada y sin votación abierta', function () {
    foreach ([$this->tokenAdmin, $this->tokenSecretario, $this->tokenA] as $t) {
        ($this->cerrar)([], $t)->assertForbidden();
    }
    $v = ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Cuota extraordinaria').'/votacion/abrir')->assertCreated()->json('data.id');
    ($this->cerrar)(['confirmar_pendientes' => true])->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_ABIERTA');
    ($this->como)()->postJson("/api/v1/votaciones/{$v}/cerrar")->assertOk();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_INSTALADA');
});

it('el acta congela lo que pasó: asistencia, quórum, mora y resultados, y no cambia después', function () {
    ($this->votarCuota)();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $c = enCondominio($this->condominio, fn () => Acta::first()->contenido);

    expect($c['instalacion'])->toMatchArray(['convocatoria' => 1, 'en_subrogacion' => false, 'quorum_alicuota_presente' => '12.0000', 'quorum_alicuota_total' => '12.0000'])
        ->and($c['padron'])->toMatchArray(['unidades' => 4, 'en_mora' => ['A-104']])
        ->and($c['asistencia'])->toHaveCount(4)->and(collect($c['asistencia'])->firstWhere('codigo', 'A-104')['en_mora'])->toBeTrue()
        ->and($c['convocatoria']['unidades'])->toBe(4);
    $cuota = collect($c['puntos'])->firstWhere('titulo', 'Cuota extraordinaria');
    expect($cuota['votaciones'][0])->toMatchArray(['resultado' => 'aprobado', 'estado' => 'cerrada'])
        ->and($cuota['votaciones'][0]['cifras']['alicuota_si'])->toBe('9.0000')->and($cuota['votaciones'][0]['integridad']['valida'])->toBeTrue();

    // Cambian las alícuotas y el nombre de quien asistió: el acta sigue igual
    enCondominio($this->condominio, fn () => Unidad::whereKey($this->unidadA->id)->update(['alicuota' => '9.0000']));
    expect(enCondominio($this->condominio, fn () => Acta::first()->contenido))->toBe($c);
    $hash = enCondominio($this->condominio, fn () => Acta::first()->hash);
    expect($hash)->toBe(Acta::huellaDe($c));
});

it('las actas se numeran por condominio', function () {
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $otra = ($this->crear)('Asamblea extraordinaria', [['titulo' => 'Informe', 'tipo' => 'informativo']]);
    ($this->cerrar)([], null, $otra)->assertOk()->assertJsonPath('data.numero', 2);

    // Otro condominio parte de 1
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Administrador);
    expect(enCondominio($otro, fn () => Acta::count()))->toBe(0);
    expect(enCondominio($this->condominio, fn () => Acta::pluck('numero')->sort()->values()->all()))->toBe([1, 2]);
});

it('las resoluciones salen de lo aprobado, no de lo rechazado', function () {
    ($this->votarCuota)();
    $rechazada = ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Reforma del reglamento').'/votacion/abrir')->assertCreated()->json('data.id');
    ($this->como)($this->tokenA)->postJson("/api/v1/votaciones/{$rechazada}/votos", ['opcion' => 'si'])->assertCreated();
    ($this->como)()->postJson("/api/v1/votaciones/{$rechazada}/cerrar")->assertOk()->assertJsonPath('data.resultado', 'rechazado');
    ($this->cerrar)()->assertOk();

    $r = enCondominio($this->condominio, fn () => Resolucion::pluck('texto')->all());
    expect($r)->toBe(['Se aprobó: Cuota extraordinaria. Cuota para el tanque de agua.']);
    expect(enCondominio($this->condominio, fn () => Resolucion::first()->vigente_desde->toDateString()))->toBe(hoyLocal()->addDays(45)->toDateString());
});

it('una elección cerrada deja una resolución por cada cargo nombrado', function () {
    $id = ($this->crear)('Asamblea con elección', [['titulo' => 'Elección de directiva', 'tipo' => 'eleccion', 'mayoria' => 'simple']]);
    $punto = ($this->punto)('Elección de directiva', $id);
    $persona = enCondominio($this->condominio, fn () => Ocupante::where('unidad_id', $this->unidadC->id)->value('persona_id'));
    ($this->como)()->postJson("/api/v1/puntos/{$punto}/candidatos", ['persona_id' => $persona, 'cargo' => 'tesorero'])->assertCreated();
    $cand = enCondominio($this->condominio, fn () => Candidato::value('id'));
    $v = ($this->como)()->postJson("/api/v1/puntos/{$punto}/votacion/abrir", ['cargo' => 'tesorero'])->assertCreated()->json('data.id');
    foreach ([$this->tokenA, $this->tokenC] as $t) {
        ($this->como)($t)->postJson("/api/v1/votaciones/{$v}/votos", ['opcion' => 'candidato', 'candidato_id' => $cand])->assertCreated();
    }
    ($this->como)()->postJson("/api/v1/votaciones/{$v}/cerrar")->assertOk();
    ($this->como)()->postJson("/api/v1/puntos/{$punto}/eleccion/cerrar", ['periodo_hasta' => hoyLocal()->addYears(2)->format('Y-m-d')])->assertOk();

    ($this->cerrar)([], null, $id)->assertOk();

    $r = enCondominio($this->condominio, fn () => Resolucion::where('asamblea_id', $id)->pluck('texto')->all());
    expect($r)->toHaveCount(1)->and($r[0])->toContain('Se eligió a')->toContain('como Tesorero');
});

it('firman el secretario y el presidente; con las dos firmas el acta queda firmada y se publica', function () {
    ($this->votarCuota)();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $id = ($this->acta)()->json('data.id');

    $uno = ($this->firmar)($id, $this->tokenSecretario)->assertOk()->json('data');
    expect($uno['estado'])->toBe('pendiente')->and($uno['secretario']['nombre'])->not->toBeNull()->and($uno['presidente']['firmada_en'])->toBeNull()->and($uno['puede_firmar'])->toBeFalse();
    ($this->firmar)($id, $this->tokenSecretario)->assertStatus(409)->assertJsonPath('error.code', 'YA_FIRMO');

    $dos = ($this->firmar)($id, $this->tokenPresidente)->assertOk()->assertJsonPath('message', 'Acta firmada y publicada.')->json('data');
    expect($dos['estado'])->toBe('firmada')->and($dos['publicada_en'])->not->toBeNull()->and($dos['presidente']['en_subrogacion'])->toBeFalse();
    expect(enCondominio($this->condominio, fn () => Asamblea::find($this->id)->estado))->toBe('acta_firmada');
    expect(enCondominio($this->condominio, fn () => Anuncio::where('titulo', 'like', 'Acta N.º 1 disponible%')->exists()))->toBeTrue();
    ($this->firmar)($id, $this->tokenPresidente)->assertStatus(409)->assertJsonPath('error.code', 'ACTA_YA_FIRMADA');
});

it('el vicepresidente firma por el presidente solo si lo declara ausente y el acta lo dice', function () {
    ($this->votarCuota)();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $id = ($this->acta)()->json('data.id');

    expect(($this->acta)($this->tokenVice)->json('data'))->toMatchArray(['tu_firma' => 'presidente', 'es_vicepresidente' => true]);
    ($this->firmar)($id, $this->tokenVice)->assertStatus(422)->assertJsonPath('error.code', 'PRESIDENTE_AUSENTE_REQUERIDO');
    $r = ($this->firmar)($id, $this->tokenVice, ['presidente_ausente' => true])->assertOk()->json('data');

    expect($r['presidente']['en_subrogacion'])->toBeTrue();
});

it('quien instaló en subrogación firma sin volver a declararlo', function () {
    $id = ($this->crear)('Asamblea en subrogación', [['titulo' => 'Informe', 'tipo' => 'informativo']]);
    enCondominio($this->condominio, fn () => Asamblea::whereKey($id)->update(['en_subrogacion' => true]));
    ($this->cerrar)([], null, $id)->assertOk();
    $acta = ($this->acta)(null, $id)->json('data.id');

    ($this->firmar)($acta, $this->tokenVice)->assertOk()->assertJsonPath('data.presidente.en_subrogacion', true);
});

it('solo la directiva firma: ni el administrador, ni el guardia, ni un propietario', function () {
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $id = ($this->acta)()->json('data.id');

    foreach ([$this->tokenAdmin, $this->tokenGuardia, $this->tokenA] as $t) {
        ($this->firmar)($id, $t)->assertForbidden();
    }
});

it('el acta no existe antes de cerrar y el propietario solo la ve firmada', function () {
    ($this->acta)()->assertStatus(409)->assertJsonPath('error.code', 'ACTA_NO_GENERADA');
    ($this->votarCuota)();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();

    ($this->acta)($this->tokenA)->assertStatus(409)->assertJsonPath('error.code', 'ACTA_NO_PUBLICADA');
    ($this->como)($this->tokenA)->get("/api/v1/asambleas/{$this->id}/acta.pdf")->assertStatus(409);
    // La mesa sí la ve, aunque falten firmas
    $pdf = ($this->como)($this->tokenSecretario)->get("/api/v1/asambleas/{$this->id}/acta.pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();
    ($this->como)($this->tokenGuardia)->get("/api/v1/asambleas/{$this->id}/acta.pdf")->assertForbidden();

    $id = ($this->acta)()->json('data.id');
    ($this->firmar)($id, $this->tokenSecretario)->assertOk();
    ($this->firmar)($id, $this->tokenPresidente)->assertOk();
    ($this->acta)($this->tokenA)->assertOk()->assertJsonPath('data.numero', 1);
    ($this->como)($this->tokenA)->get("/api/v1/asambleas/{$this->id}/acta.pdf")->assertOk();
});

it('los propietarios ven las resoluciones solo de actas firmadas; la mesa, todas', function () {
    ($this->votarCuota)();
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();

    expect(($this->como)($this->tokenA)->getJson('/api/v1/resoluciones')->assertOk()->json('data'))->toBe([]);
    expect(($this->como)($this->tokenSecretario)->getJson('/api/v1/resoluciones')->assertOk()->json('data'))->toHaveCount(1);

    $id = ($this->acta)()->json('data.id');
    ($this->firmar)($id, $this->tokenSecretario)->assertOk();
    ($this->firmar)($id, $this->tokenPresidente)->assertOk();
    $r = ($this->como)($this->tokenA)->getJson('/api/v1/resoluciones')->assertOk()->json('data');
    expect($r)->toHaveCount(1)->and($r[0])->toMatchArray(['asamblea' => 'Asamblea ordinaria 2026', 'acta_numero' => 1, 'publicada' => true])
        ->and($r[0]['texto'])->toContain('Cuota extraordinaria');
    ($this->como)($this->tokenGuardia)->getJson('/api/v1/resoluciones')->assertForbidden();
});

it('mis asambleas trae el acta cuando ya está firmada', function () {
    ($this->cerrarYFirmar)();

    $mis = ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data.0');
    expect($mis)->toMatchArray(['estado' => 'acta_firmada', 'puede_dar_poder' => false, 'puede_marcar_presencia' => false])
        ->and($mis['acta'])->toMatchArray(['asamblea_id' => $this->id, 'numero' => 1]);
});

it('mis asambleas no trae el acta mientras faltan firmas', function () {
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();

    $mis = ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data.0');
    expect($mis['estado'])->toBe('cerrada')->and($mis['acta'])->toBeNull();
});

it('el acta de un condominio no se ve en otro', function () {
    ($this->cerrar)(['confirmar_pendientes' => true])->assertOk();
    $id = ($this->acta)()->json('data.id');
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Secretario);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->postJson("/api/v1/actas/{$id}/firmar")->assertNotFound();
    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson("/api/v1/asambleas/{$this->id}/acta")->assertNotFound();
});
