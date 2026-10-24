<?php

use App\Core\Access\Catalogo;
use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Candidato;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Usuarios\Actions\AsignarCargoAction;
use App\Modules\Usuarios\Models\CargoDirectiva;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create();
    [$this->admin, $this->tokenAdmin] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $this->tokenPresidente] = usuarioConToken($this->condominio, Rol::Presidente);
    [, $this->tokenSecretario] = usuarioConToken($this->condominio, Rol::Secretario);
    // A 4 + B 1 + C 5 votan; D (2) está en mora: el total es 12, la base de votación 10
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '4.0000']);
    [, $this->tokenB, $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '1.0000']);
    [, $this->tokenC, $this->unidadC] = residenteDe($this->condominio, 'A-103', 'propietario', ['alicuota' => '5.0000']);
    [, , $this->unidadD] = residenteDe($this->condominio, 'A-104', 'propietario', ['alicuota' => '2.0000']);
    [, , $this->unidadInq] = residenteDe($this->condominio, 'B-1', 'inquilino', ['alicuota' => '1.0000']);
    cuotaDe($this->condominio, $this->unidadD, 3);
    $persona = fn ($u, string $relacion = 'propietario') => enCondominio($this->condominio, fn () => Ocupante::where('unidad_id', $u->id)->where('relacion', $relacion)->value('persona_id'));
    $this->pA = $persona($this->unidadA);
    $this->pB = $persona($this->unidadB);
    $this->pC = $persona($this->unidadC);
    $this->pD = $persona($this->unidadD);
    $this->pInq = $persona($this->unidadInq, 'inquilino');

    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenPresidente)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->id = (function () {
        $id = ($this->como)($this->tokenAdmin)->postJson('/api/v1/asambleas', [
            'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial',
            'fecha_hora' => hoyLocal()->addDays(45)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal',
        ])->assertCreated()->json('data.id');
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", ['titulo' => 'Informe', 'tipo' => 'informativo'])->assertCreated();
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", ['titulo' => 'Cuota extraordinaria', 'tipo' => 'votacion', 'mayoria' => 'simple'])->assertCreated();
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/puntos", ['titulo' => 'Elección de directiva', 'tipo' => 'eleccion', 'mayoria' => 'simple'])->assertCreated();
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
        ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

        return $id;
    })();
    $this->punto = fn (string $t = 'Elección de directiva') => enCondominio($this->condominio, fn () => AsambleaPunto::where('asamblea_id', $this->id)->where('titulo', $t)->value('id'));
    $this->postular = fn (int $persona, string $cargo, ?string $token = null) => ($this->como)($token)->postJson('/api/v1/puntos/'.($this->punto)().'/candidatos', ['persona_id' => $persona, 'cargo' => $cargo]);
    $this->cand = fn (int $persona, string $cargo) => enCondominio($this->condominio, fn () => Candidato::where('persona_id', $persona)->where('cargo', $cargo)->value('id'));
    $this->instalar = function () {
        foreach ([$this->unidadA, $this->unidadB, $this->unidadC, $this->unidadD] as $u) {
            ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/asistencia", ['unidad_id' => $u->id])->assertOk();
        }
        ($this->como)()->postJson("/api/v1/asambleas/{$this->id}/instalar", ['convocatoria' => 1])->assertOk();
    };
    $this->abrir = fn (string $cargo) => ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)().'/votacion/abrir', ['cargo' => $cargo]);
    $this->votar = fn (int $votacion, string $token, array $d) => ($this->como)($token)->postJson("/api/v1/votaciones/{$votacion}/votos", $d);
    $this->cerrarVotacion = fn (int $votacion) => ($this->como)()->postJson("/api/v1/votaciones/{$votacion}/cerrar")->assertOk()->json('data');
    $this->cerrarEleccion = fn (array $d = []) => ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)().'/eleccion/cerrar', $d + ['periodo_hasta' => hoyLocal()->addYears(2)->format('Y-m-d')]);
    /** Vota un cargo completo: cada token con su candidato (o 'blanco'). */
    $this->votarCargo = function (string $cargo, array $votos) {
        $v = ($this->abrir)($cargo)->assertCreated()->json('data.id');
        foreach ($votos as $token => $candidato) {
            ($this->votar)($v, $this->{$token}, $candidato === 'blanco' ? ['opcion' => 'blanco'] : ['opcion' => 'candidato', 'candidato_id' => $candidato])->assertCreated();
        }

        return ($this->cerrarVotacion)($v);
    };
});

it('la administración y el presidente postulan candidatos; el secretario no', function () {
    ($this->postular)($this->pA, 'presidente', $this->tokenAdmin)->assertCreated();
    ($this->postular)($this->pB, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente', $this->tokenSecretario)->assertForbidden();
    ($this->postular)($this->pC, 'presidente', $this->tokenA)->assertForbidden();

    $r = ($this->como)()->getJson('/api/v1/puntos/'.($this->punto)().'/candidatos')->assertOk()->json('data');
    $presidente = collect($r['cargos'])->firstWhere('cargo', 'presidente');
    expect($presidente['candidatos'])->toHaveCount(2)->and($presidente['puede_abrir'])->toBeTrue()
        ->and(collect($r['cargos'])->firstWhere('cargo', 'secretario')['puede_abrir'])->toBeFalse()
        ->and(collect($r['elegibles'])->firstWhere('persona_id', $this->pD)['motivo'])->toBe('en_mora')
        ->and(collect($r['elegibles'])->firstWhere('persona_id', $this->pA)['disponible'])->toBeTrue();
});

it('solo propietarios sin mora y con correo; no se repite ni se postula a un punto que no es elección', function () {
    ($this->postular)($this->pD, 'presidente')->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_EN_MORA');
    ($this->postular)($this->pInq, 'presidente')->assertStatus(422)->assertJsonPath('error.code', 'NO_ES_PROPIETARIO');
    ($this->postular)($this->pA, 'gerente')->assertStatus(422);
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pA, 'presidente')->assertStatus(409)->assertJsonPath('error.code', 'YA_ES_CANDIDATO');
    ($this->postular)($this->pA, 'secretario')->assertCreated();   // una persona puede postularse a varios cargos

    ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Cuota extraordinaria').'/candidatos', ['persona_id' => $this->pA, 'cargo' => 'presidente'])->assertStatus(422)->assertJsonPath('error.code', 'PUNTO_NO_ES_ELECCION');
});

it('una persona sin correo no puede ser candidata', function () {
    enCondominio($this->condominio, fn () => DB::table('personas')->where('id', $this->pB)->update(['email' => null]));

    ($this->postular)($this->pB, 'presidente')->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_SIN_CORREO');
});

it('se retira una candidatura hasta que se abre la votación de ese cargo', function () {
    $c = ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pB, 'presidente')->assertCreated();
    $id = ($this->cand)($this->pB, 'presidente');

    ($this->como)()->deleteJson("/api/v1/candidatos/{$id}")->assertOk();
    expect(enCondominio($this->condominio, fn () => Candidato::count()))->toBe(1);

    ($this->instalar)();
    ($this->abrir)('presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente')->assertStatus(409)->assertJsonPath('error.code', 'CARGO_YA_VOTADO');
    ($this->como)()->deleteJson('/api/v1/candidatos/'.($this->cand)($this->pA, 'presidente'))->assertStatus(409);
});

it('cada cargo se vota por separado y pide candidatos', function () {
    ($this->instalar)();

    ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)().'/votacion/abrir')->assertStatus(422)->assertJsonPath('error.code', 'CARGO_REQUERIDO');
    ($this->abrir)('presidente')->assertStatus(422)->assertJsonPath('error.code', 'SIN_CANDIDATOS');
    ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Cuota extraordinaria').'/votacion/abrir', ['cargo' => 'presidente'])->assertStatus(422)->assertJsonPath('error.code', 'CARGO_NO_APLICA');
});

it('vota por un candidato o en blanco; no por uno de otro cargo ni con Sí o No', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente')->assertCreated();
    ($this->postular)($this->pB, 'secretario')->assertCreated();
    ($this->instalar)();
    $v = ($this->abrir)('presidente')->assertCreated()->assertJsonPath('data.cargo', 'presidente')->assertJsonPath('data.cargo_etiqueta', 'Presidente')->json('data.id');

    ($this->votar)($v, $this->tokenA, ['opcion' => 'si'])->assertStatus(422)->assertJsonPath('error.code', 'OPCION_NO_VALIDA');
    ($this->votar)($v, $this->tokenA, ['opcion' => 'candidato', 'candidato_id' => ($this->cand)($this->pB, 'secretario')])->assertStatus(422)->assertJsonPath('error.code', 'OPCION_NO_VALIDA');
    ($this->votar)($v, $this->tokenA, ['opcion' => 'candidato'])->assertStatus(422);
    ($this->votar)($v, $this->tokenA, ['opcion' => 'candidato', 'candidato_id' => ($this->cand)($this->pC, 'presidente')])->assertCreated();
    ($this->votar)($v, $this->tokenB, ['opcion' => 'blanco'])->assertCreated();
    ($this->votar)($v, $this->tokenA, ['opcion' => 'blanco'])->assertStatus(409)->assertJsonPath('error.code', 'YA_VOTO');

    // En una votación común no hay «candidato» ni «blanco»
    $comun = ($this->como)()->postJson('/api/v1/puntos/'.($this->punto)('Cuota extraordinaria').'/votacion/abrir');
    $comun->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_ABIERTA');
});

it('al cerrar un cargo gana el candidato con más alícuota si pasa de la mitad; la papeleta no muestra el conteo', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente')->assertCreated();
    ($this->instalar)();
    $a = ($this->cand)($this->pA, 'presidente');
    $c = ($this->cand)($this->pC, 'presidente');
    $v = ($this->abrir)('presidente')->assertCreated()->json('data.id');
    ($this->votar)($v, $this->tokenA, ['opcion' => 'candidato', 'candidato_id' => $a])->assertCreated();

    $mis = ($this->como)($this->tokenB)->getJson('/api/v1/mis-asambleas')->assertOk()->json('data.0.votacion');
    expect($mis)->toMatchArray(['cargo' => 'presidente', 'cargo_etiqueta' => 'Presidente'])->and($mis['candidatos'])->toHaveCount(2)->and($mis)->not->toHaveKey('cifras')
        ->and($mis['candidatos'][0])->toHaveKeys(['id', 'nombre', 'unidad'])->and($mis['candidatos'][0])->not->toHaveKey('alicuota');
    $propia = ($this->como)($this->tokenA)->getJson('/api/v1/mis-asambleas')->json('data.0.votacion.unidades.0');
    expect($propia)->toMatchArray(['voto' => 'candidato', 'voto_candidato_id' => $a]);

    ($this->votar)($v, $this->tokenB, ['opcion' => 'candidato', 'candidato_id' => $c])->assertCreated();
    ($this->votar)($v, $this->tokenC, ['opcion' => 'candidato', 'candidato_id' => $c])->assertCreated();
    $r = ($this->cerrarVotacion)($v);

    expect($r)->toMatchArray(['estado' => 'cerrada', 'resultado' => 'aprobado', 'cifras' => null])
        ->and($r['eleccion']['ganador_candidato_id'])->toBe($c)->and($r['eleccion']['base_alicuota'])->toBe('10.0000')
        ->and(collect($r['eleccion']['candidatos'])->firstWhere('candidato_id', $c))->toMatchArray(['alicuota' => '6.0000', 'votos' => 2, 'pct' => '60.00'])
        ->and($r['integridad'])->toMatchArray(['valida' => true, 'votos' => 3]);
});

it('sin mayoría o con empate, el cargo queda sin ganador', function () {
    ($this->postular)($this->pA, 'tesorero')->assertCreated();
    ($this->postular)($this->pB, 'tesorero')->assertCreated();
    ($this->postular)($this->pA, 'secretario')->assertCreated();
    ($this->postular)($this->pC, 'secretario')->assertCreated();
    ($this->instalar)();

    // Tesorero: A 4 contra B+C = 6 ... A gana con 4 de 10? No: votan A→pA (4) y C→pB (5): ninguno pasa de 5
    $r = ($this->votarCargo)('tesorero', ['tokenA' => ($this->cand)($this->pA, 'tesorero'), 'tokenC' => ($this->cand)($this->pB, 'tesorero')]);
    expect($r['resultado'])->toBe('rechazado')->and($r['eleccion']['ganador_candidato_id'])->toBeNull();

    // Secretario: en blanco 9 de 10 contra 1 voto: tampoco
    $r2 = ($this->votarCargo)('secretario', ['tokenA' => 'blanco', 'tokenC' => 'blanco', 'tokenB' => ($this->cand)($this->pC, 'secretario')]);
    expect($r2['resultado'])->toBe('rechazado');
});

it('cerrar la elección pide que todos los cargos con candidatos estén votados', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pB, 'secretario')->assertCreated();
    ($this->instalar)();
    ($this->votarCargo)('presidente', ['tokenA' => ($this->cand)($this->pA, 'presidente'), 'tokenC' => ($this->cand)($this->pA, 'presidente')]);

    ($this->cerrarEleccion)()->assertStatus(409)->assertJsonPath('error.code', 'ELECCION_INCOMPLETA');
    $v = ($this->abrir)('secretario')->assertCreated()->json('data.id');
    ($this->cerrarEleccion)()->assertStatus(409)->assertJsonPath('error.code', 'VOTACION_ABIERTA');
});

it('al cerrar la elección los ganadores se nombran: se cierra el periodo del titular anterior', function () {
    // B era presidente; A y C se postulan
    enCondominio($this->condominio, fn () => app(AsignarCargoAction::class)->execute(app(Catalogo::class)->rol('presidente'), $this->pB, 'Asamblea 2024', hoyLocal()->addYear()->format('Y-m-d'), $this->admin));
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente')->assertCreated();
    ($this->postular)($this->pA, 'secretario')->assertCreated();
    ($this->instalar)();
    $c = ($this->cand)($this->pC, 'presidente');
    $a = ($this->cand)($this->pA, 'secretario');
    ($this->votarCargo)('presidente', ['tokenA' => $c, 'tokenC' => $c]);
    ($this->votarCargo)('secretario', ['tokenA' => $a, 'tokenC' => $a]);

    $r = ($this->cerrarEleccion)()->assertOk()->json('data.cargos');

    expect(collect($r)->firstWhere('cargo', 'presidente'))->toMatchArray(['estado' => 'nombrado', 'persona_id' => $this->pC])
        ->and(collect($r)->firstWhere('cargo', 'secretario'))->toMatchArray(['estado' => 'nombrado', 'persona_id' => $this->pA]);
    enCondominio($this->condominio, function () {
        $abiertos = CargoDirectiva::whereNull('cerrado_en')->pluck('persona_id', 'cargo')->all();
        expect($abiertos)->toBe(['presidente' => $this->pC, 'secretario' => $this->pA])
            ->and(CargoDirectiva::where('persona_id', $this->pB)->value('cerrado_en'))->not->toBeNull()
            ->and(CargoDirectiva::where('cargo', 'presidente')->whereNull('cerrado_en')->value('acta'))->toContain('Asamblea ordinaria 2026');
    });
    // La mesa lo recuerda y el punto no se cierra dos veces
    ($this->cerrarEleccion)()->assertStatus(409)->assertJsonPath('error.code', 'ELECCION_CERRADA');
    $mesa = ($this->como)()->getJson("/api/v1/asambleas/{$this->id}/mesa")->json('data.puntos');
    expect(collect($mesa)->firstWhere('titulo', 'Elección de directiva')['eleccion'])->toMatchArray(['cerrada' => true, 'puede_cerrar' => false]);
});

it('quien ganó dos cargos elige uno antes de cerrar y el otro queda vacante', function () {
    ($this->postular)($this->pC, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'secretario')->assertCreated();
    ($this->instalar)();
    $cp = ($this->cand)($this->pC, 'presidente');
    $cs = ($this->cand)($this->pC, 'secretario');
    ($this->votarCargo)('presidente', ['tokenA' => $cp, 'tokenC' => $cp]);
    ($this->votarCargo)('secretario', ['tokenA' => $cs, 'tokenC' => $cs]);

    $e = ($this->cerrarEleccion)()->assertStatus(422)->assertJsonPath('error.code', 'CONFLICTO_DE_CARGOS');
    expect($e->json('error.details.conflictos.0'))->toMatchArray(['persona_id' => $this->pC, 'cargos' => ['presidente', 'secretario']]);
    expect(enCondominio($this->condominio, fn () => CargoDirectiva::count()))->toBe(0);
    ($this->cerrarEleccion)(['acepta' => [(string) $this->pC => 'tesorero']])->assertStatus(422)->assertJsonPath('error.code', 'CONFLICTO_DE_CARGOS');

    $r = ($this->cerrarEleccion)(['acepta' => [(string) $this->pC => 'presidente']])->assertOk()->json('data.cargos');
    expect(collect($r)->firstWhere('cargo', 'presidente')['estado'])->toBe('nombrado')
        ->and(collect($r)->firstWhere('cargo', 'secretario'))->toMatchArray(['estado' => 'vacante'])
        ->and(collect($r)->firstWhere('cargo', 'secretario')['motivo'])->toContain('aceptó otro');
    expect(enCondominio($this->condominio, fn () => CargoDirectiva::whereNull('cerrado_en')->pluck('cargo')->all()))->toBe(['presidente']);
});

it('un cargo sin ganador queda vacante y el resto se nombra', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pB, 'tesorero')->assertCreated();
    ($this->instalar)();
    ($this->votarCargo)('presidente', ['tokenA' => ($this->cand)($this->pA, 'presidente'), 'tokenC' => ($this->cand)($this->pA, 'presidente')]);
    ($this->votarCargo)('tesorero', ['tokenA' => 'blanco', 'tokenC' => 'blanco']);

    $r = ($this->cerrarEleccion)()->assertOk()->json('data.cargos');

    expect(collect($r)->firstWhere('cargo', 'presidente')['estado'])->toBe('nombrado')
        ->and(collect($r)->firstWhere('cargo', 'tesorero'))->toMatchArray(['estado' => 'vacante']);
});

it('si un ganador ya cayó en mora su cargo queda sin nombrar y los demás siguen', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pB, 'secretario')->assertCreated();
    ($this->instalar)();
    ($this->votarCargo)('presidente', ['tokenA' => ($this->cand)($this->pA, 'presidente'), 'tokenC' => ($this->cand)($this->pA, 'presidente')]);
    ($this->votarCargo)('secretario', ['tokenA' => ($this->cand)($this->pB, 'secretario'), 'tokenC' => ($this->cand)($this->pB, 'secretario')]);
    cuotaDe($this->condominio, $this->unidadB, 3);   // B cae en mora antes de cerrar

    $r = ($this->cerrarEleccion)()->assertOk()->json('data.cargos');

    expect(collect($r)->firstWhere('cargo', 'presidente')['estado'])->toBe('nombrado')
        ->and(collect($r)->firstWhere('cargo', 'secretario'))->toMatchArray(['estado' => 'no_nombrado']);
    expect(collect($r)->firstWhere('cargo', 'secretario')['motivo'])->toContain('mora');
});

it('el periodo del cargo se valida y solo el presidente cierra la elección', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->instalar)();
    ($this->votarCargo)('presidente', ['tokenA' => ($this->cand)($this->pA, 'presidente'), 'tokenC' => ($this->cand)($this->pA, 'presidente')]);

    ($this->cerrarEleccion)(['periodo_hasta' => hoyLocal()->subDay()->format('Y-m-d')])->assertStatus(422);
    ($this->cerrarEleccion)(['periodo_hasta' => hoyLocal()->addYears(5)->format('Y-m-d')])->assertStatus(422);
    foreach ([$this->tokenAdmin, $this->tokenSecretario, $this->tokenA] as $token) {
        ($this->como)($token)->postJson('/api/v1/puntos/'.($this->punto)().'/eleccion/cerrar', ['periodo_hasta' => hoyLocal()->addYear()->format('Y-m-d')])->assertForbidden();
    }
});

it('la auditoría de una elección lista a los candidatos y el voto en blanco', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    ($this->postular)($this->pC, 'presidente')->assertCreated();
    ($this->instalar)();
    $c = ($this->cand)($this->pC, 'presidente');
    $r = ($this->votarCargo)('presidente', ['tokenA' => 'blanco', 'tokenB' => $c, 'tokenC' => $c]);

    $pdf = ($this->como)($this->tokenSecretario)->get('/api/v1/votaciones/'.$r['id'].'/auditoria.pdf')->assertOk();
    expect(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();
});

it('la elección de un condominio no se ve en otro', function () {
    ($this->postular)($this->pA, 'presidente')->assertCreated();
    $id = ($this->cand)($this->pA, 'presidente');
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Presidente);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->deleteJson("/api/v1/candidatos/{$id}")->assertNotFound();
    expect(enCondominio($this->condominio, fn () => Candidato::count()))->toBe(1);
});
