<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Una unidad con alícuota y propietario vigente. */
function unidadConPropietario(Condominio $condominio, string $codigo, string $alicuota, string $relacion = 'propietario'): Unidad
{
    return enCondominio($condominio, function () use ($codigo, $alicuota, $relacion) {
        $u = Unidad::factory()->create(['codigo' => $codigo, 'alicuota' => $alicuota]);
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => Persona::factory()->create()->id, 'relacion' => $relacion, 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);

        return $u;
    });
}

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    $this->tokens = [];
    foreach (['admin' => Rol::Administrador, 'presidente' => Rol::Presidente, 'contador' => Rol::Contador, 'guardia' => Rol::Guardia, 'residente' => Rol::Residente] as $clave => $rol) {
        [$u, $this->tokens[$clave]] = usuarioConToken($this->condominio, $rol);
        if ($rol === Rol::Contador) {
            $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        }
    }
    $this->como = function (string $clave) {
        cambiarDeUsuario();

        return $this->withToken($this->tokens[$clave])->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->fecha = hoyLocal()->addDays(45)->format('Y-m-d').'T19:00';
    $this->borrador = fn (array $extra = []) => ($this->como)('admin')->postJson('/api/v1/asambleas', $extra + [
        'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial', 'fecha_hora' => $this->fecha, 'lugar' => 'Salón comunal',
    ]);
    $this->punto = fn (int $id, array $extra = []) => ($this->como)('admin')->postJson("/api/v1/asambleas/{$id}/puntos", $extra + ['titulo' => 'Informe de la administración', 'tipo' => 'informativo']);
});

it('crea el borrador de una asamblea y lo devuelve con la hora local', function () {
    $r = ($this->borrador)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial', 'estado' => 'borrador', 'fecha_hora' => $this->fecha, 'lugar' => 'Salón comunal', 'puede_convocar' => false])
        ->and($r->json('data.segunda_convocatoria'))->toBe('20:00');
    // Se guarda en UTC (Ecuador es UTC-5): las 19:00 locales son las 00:00 del día siguiente
    expect(enCondominio($this->condominio, fn () => Asamblea::first()->fecha_hora->format('H:i')))->toBe('00:00');
});

it('el administrador y el presidente preparan; el contador, el guardia y el residente no', function () {
    ($this->borrador)()->assertCreated();
    ($this->como)('presidente')->getJson('/api/v1/asambleas')->assertOk();
    foreach (['contador', 'guardia', 'residente'] as $quien) {
        ($this->como)($quien)->getJson('/api/v1/asambleas')->assertForbidden();
        ($this->como)($quien)->postJson('/api/v1/asambleas', ['titulo' => 'x'])->assertForbidden();
    }
});

it('valida los datos y que la fecha sea futura', function () {
    ($this->borrador)(['titulo' => 'ab'])->assertStatus(422);
    ($this->borrador)(['tipo' => 'anual'])->assertStatus(422);
    ($this->borrador)(['fecha_hora' => '17/10/2026 19:00'])->assertStatus(422);
    ($this->borrador)(['fecha_hora' => hoyLocal()->subDay()->format('Y-m-d').'T19:00'])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_PASADA');
    ($this->borrador)(['lugar' => ''])->assertStatus(422)->assertJsonPath('error.code', 'LUGAR_REQUERIDO');
});

it('las asambleas virtuales o mixtas solo se permiten si el reglamento del condominio lo permite', function () {
    $virtual = ['modalidad' => 'mixta', 'enlace_video' => 'https://meet.example.com/abc'];

    ($this->borrador)($virtual)->assertStatus(422)->assertJsonPath('error.code', 'MODALIDAD_NO_PERMITIDA');

    ($this->como)('admin')->putJson('/api/v1/asambleas/configuracion', ['permite_virtual' => true, 'mora_cuenta_para_quorum' => true, 'voto_secreto' => false, 'porcentaje_reforma' => null])->assertOk();

    ($this->borrador)(['modalidad' => 'mixta'])->assertStatus(422)->assertJsonPath('error.code', 'ENLACE_REQUERIDO');
    ($this->borrador)(['modalidad' => 'mixta', 'enlace_video' => 'http://inseguro.example.com'])->assertStatus(422);
    ($this->borrador)($virtual)->assertCreated()->assertJsonPath('data.enlace_video', 'https://meet.example.com/abc');
    // Virtual no necesita lugar, y el enlace se descarta en una presencial
    ($this->borrador)(['modalidad' => 'virtual', 'lugar' => null, 'enlace_video' => 'https://zoom.example.com/j/1'])->assertCreated();
    ($this->borrador)(['enlace_video' => 'https://zoom.example.com/j/1'])->assertCreated()->assertJsonPath('data.enlace_video', null);
});

it('edita y descarta un borrador, pero no una asamblea ya convocada', function () {
    $id = ($this->borrador)()->json('data.id');

    ($this->como)('admin')->patchJson("/api/v1/asambleas/{$id}", ['titulo' => 'Asamblea extraordinaria', 'tipo' => 'extraordinaria', 'modalidad' => 'presencial', 'fecha_hora' => $this->fecha, 'lugar' => 'Piscina'])
        ->assertOk()->assertJsonPath('data.titulo', 'Asamblea extraordinaria');

    enCondominio($this->condominio, fn () => Asamblea::whereKey($id)->update(['estado' => 'convocada']));
    ($this->como)('admin')->patchJson("/api/v1/asambleas/{$id}", ['titulo' => 'Otro', 'tipo' => 'ordinaria', 'modalidad' => 'presencial', 'fecha_hora' => $this->fecha, 'lugar' => 'x'])
        ->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_EDITABLE');
    ($this->como)('admin')->deleteJson("/api/v1/asambleas/{$id}")->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_EDITABLE');
    ($this->punto)($id)->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_EDITABLE');

    $otro = ($this->borrador)()->json('data.id');
    ($this->como)('admin')->deleteJson("/api/v1/asambleas/{$otro}")->assertOk();
    expect(enCondominio($this->condominio, fn () => Asamblea::whereKey($otro)->exists()))->toBeFalse();
});

it('el orden del día: cada tipo de punto fija su mayoría', function () {
    $id = ($this->borrador)()->json('data.id');

    ($this->punto)($id, ['titulo' => 'Constatación del quórum', 'tipo' => 'informativo', 'mayoria' => 'simple'])->assertCreated();
    ($this->punto)($id, ['titulo' => 'Aprobación de estados financieros', 'tipo' => 'votacion'])->assertCreated();
    ($this->punto)($id, ['titulo' => 'Presupuesto 2027', 'tipo' => 'votacion', 'mayoria' => 'calificada', 'umbral_pct' => 60, 'base' => 'presentes'])->assertCreated();
    ($this->punto)($id, ['titulo' => 'Elección de directiva 2027', 'tipo' => 'eleccion'])->assertCreated();

    $puntos = collect(($this->como)('admin')->getJson("/api/v1/asambleas/{$id}")->json('data.puntos'));
    expect($puntos->pluck('orden')->all())->toBe([1, 2, 3, 4])
        ->and($puntos[0])->toMatchArray(['tipo' => 'informativo', 'mayoria' => 'ninguna', 'umbral_pct' => null, 'base' => null])
        ->and($puntos[1])->toMatchArray(['mayoria' => 'simple', 'base' => 'presentes', 'umbral_pct' => null])
        ->and($puntos[2])->toMatchArray(['mayoria' => 'calificada', 'umbral_pct' => '60.00', 'base' => 'presentes'])
        ->and($puntos[3])->toMatchArray(['tipo' => 'eleccion', 'mayoria' => 'simple']);
});

it('la reforma del reglamento es calificada sobre el total y su porcentaje no se inventa', function () {
    $id = ($this->borrador)()->json('data.id');

    ($this->punto)($id, ['titulo' => 'Reforma: horario de áreas', 'tipo' => 'reforma_reglamento'])->assertStatus(422)->assertJsonPath('error.code', 'UMBRAL_REQUERIDO');
    ($this->punto)($id, ['titulo' => 'Reforma: horario de áreas', 'tipo' => 'reforma_reglamento', 'umbral_pct' => 40])->assertStatus(422);

    // Con el porcentaje guardado en las reglas del condominio, el punto lo toma solo
    ($this->como)('admin')->putJson('/api/v1/asambleas/configuracion', ['permite_virtual' => false, 'mora_cuenta_para_quorum' => true, 'voto_secreto' => false, 'porcentaje_reforma' => 66.67])->assertOk();
    ($this->punto)($id, ['titulo' => 'Reforma: horario de áreas', 'tipo' => 'reforma_reglamento'])->assertCreated();
    ($this->punto)($id, ['titulo' => 'Reforma: mascotas', 'tipo' => 'reforma_reglamento', 'umbral_pct' => 75, 'base' => 'presentes'])->assertCreated();

    $puntos = collect(($this->como)('admin')->getJson("/api/v1/asambleas/{$id}")->json('data.puntos'));
    expect($puntos[0])->toMatchArray(['mayoria' => 'calificada', 'umbral_pct' => '66.67', 'base' => 'total'])
        ->and($puntos[1])->toMatchArray(['umbral_pct' => '75.00', 'base' => 'total']);
});

it('sube, baja y quita puntos manteniendo el orden', function () {
    $id = ($this->borrador)()->json('data.id');
    foreach (['Uno', 'Dos', 'Tres'] as $t) {
        ($this->punto)($id, ['titulo' => $t]);
    }
    $ids = fn () => collect(($this->como)('admin')->getJson("/api/v1/asambleas/{$id}")->json('data.puntos'));
    [$uno, $dos, $tres] = $ids()->pluck('id')->all();

    ($this->como)('admin')->postJson("/api/v1/asambleas/{$id}/puntos/{$tres}/mover", ['direccion' => 'sube'])->assertOk();
    expect($ids()->pluck('titulo')->all())->toBe(['Uno', 'Tres', 'Dos']);
    ($this->como)('admin')->postJson("/api/v1/asambleas/{$id}/puntos/{$uno}/mover", ['direccion' => 'sube'])->assertOk();   // ya es el primero: no pasa nada
    ($this->como)('admin')->postJson("/api/v1/asambleas/{$id}/puntos/{$dos}/mover", ['direccion' => 'baja'])->assertOk();   // ya es el último
    expect($ids()->pluck('titulo')->all())->toBe(['Uno', 'Tres', 'Dos']);

    ($this->como)('admin')->deleteJson("/api/v1/asambleas/{$id}/puntos/{$uno}")->assertOk();
    expect($ids()->pluck('titulo')->all())->toBe(['Tres', 'Dos'])->and($ids()->pluck('orden')->all())->toBe([1, 2]);
});

it('el orden del día admite hasta 40 puntos', function () {
    $id = ($this->borrador)()->json('data.id');
    enCondominio($this->condominio, function () use ($id) {
        for ($i = 1; $i <= 40; $i++) {
            AsambleaPunto::create(['asamblea_id' => $id, 'orden' => $i, 'titulo' => "Punto {$i}", 'tipo' => 'informativo', 'mayoria' => 'ninguna']);
        }
    });

    ($this->punto)($id)->assertStatus(422)->assertJsonPath('error.code', 'DEMASIADOS_PUNTOS');
});

it('adjunta el informe de un punto en PDF y lo reemplaza o quita', function () {
    Storage::fake('s3');
    $id = ($this->borrador)()->json('data.id');
    $punto = ($this->punto)($id)->json('data.puntos.0.id');
    $url = "/api/v1/asambleas/{$id}/puntos/{$punto}/adjunto";
    $subir = fn (UploadedFile $f) => ($this->como)('admin')->post($url, ['archivo' => $f], ['Accept' => 'application/json']);

    $subir(UploadedFile::fake()->create('informe.pdf', 100, 'application/pdf'))->assertOk()->assertJsonPath('data.puntos.0.adjunto_nombre', 'informe.pdf');
    $subir(UploadedFile::fake()->image('foto.png'))->assertStatus(422);
    $subir(UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'))->assertStatus(422);

    $primero = enCondominio($this->condominio, fn () => AsambleaPunto::find($punto)->adjunto_ruta);
    $subir(UploadedFile::fake()->create('nuevo.pdf', 50, 'application/pdf'))->assertOk()->assertJsonPath('data.puntos.0.adjunto_nombre', 'nuevo.pdf');
    Storage::disk('s3')->assertMissing($primero);

    ($this->como)('admin')->deleteJson($url)->assertOk()->assertJsonPath('data.puntos.0.adjunto_nombre', null);
    expect(enCondominio($this->condominio, fn () => AsambleaPunto::find($punto)->adjunto_ruta))->toBeNull();
});

it('el padrón cuenta propietarios, alícuotas y la mora de hoy; el inquilino no vota', function () {
    unidadConPropietario($this->condominio, 'A-101', '0.6200');
    $moroso = unidadConPropietario($this->condominio, 'A-102', '0.7100');
    unidadConPropietario($this->condominio, 'A-103', '1.0400');
    unidadConPropietario($this->condominio, 'B-1', '0.5000', 'inquilino');     // sin propietario vigente: no entra
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'P-1', 'alicuota' => null]));    // sin alícuota: no pesa
    cuotaDe($this->condominio, $moroso, 3);

    $p = ($this->como)('admin')->getJson('/api/v1/asambleas/padron')->assertOk()->json('data');

    expect($p)->toMatchArray(['unidades' => 3, 'suma_alicuotas' => '2.37', 'quorum_minimo_pct' => '50.00'])
        ->and($p['en_mora'])->toBe(['unidades' => 1, 'alicuotas' => '0.71']);
});

it('el detalle dice si se llega a convocar a tiempo (8 días hábiles) y qué falta', function () {
    // Lejos: llega a tiempo, pero sin orden del día no se puede convocar
    $lejos = ($this->borrador)()->json('data');
    expect($lejos['anticipacion'])->toMatchArray(['cumple' => true, 'minimo' => 8, 'segunda_convocatoria' => '20:00'])
        ->and($lejos['anticipacion']['dias_habiles'])->toBeGreaterThanOrEqual(8)
        ->and($lejos['falta_para_convocar'])->toBe(['Agrega al menos un punto al orden del día.'])
        ->and($lejos['puede_convocar'])->toBeFalse();

    ($this->punto)($lejos['id']);
    expect(($this->como)('admin')->getJson("/api/v1/asambleas/{$lejos['id']}")->json('data'))->toMatchArray(['puede_convocar' => true, 'falta_para_convocar' => []]);

    // Mañana: no hay 8 días hábiles
    $manana = ($this->borrador)(['fecha_hora' => hoyLocal()->addDay()->format('Y-m-d').'T19:00'])->json('data');
    ($this->punto)($manana['id']);
    $d = ($this->como)('admin')->getJson("/api/v1/asambleas/{$manana['id']}")->json('data');
    expect($d['anticipacion']['cumple'])->toBeFalse()->and($d['puede_convocar'])->toBeFalse()
        ->and($d['falta_para_convocar'][0])->toContain('8 días hábiles');
});

it('las reglas de asamblea parten de los valores de la ley y se validan', function () {
    $r = ($this->como)('admin')->getJson('/api/v1/asambleas/configuracion')->assertOk();
    expect($r->json('data'))->toBe(['permite_virtual' => false, 'mora_cuenta_para_quorum' => true, 'voto_secreto' => false, 'porcentaje_reforma' => null]);

    $guardar = fn (array $extra) => ($this->como)('admin')->putJson('/api/v1/asambleas/configuracion', $extra + ['permite_virtual' => false, 'mora_cuenta_para_quorum' => true, 'voto_secreto' => true, 'porcentaje_reforma' => null]);
    $guardar(['porcentaje_reforma' => 50])->assertStatus(422);
    $guardar(['porcentaje_reforma' => 101])->assertStatus(422);
    $guardar(['porcentaje_reforma' => 66.666])->assertStatus(422);
    $guardar(['porcentaje_reforma' => 66.67])->assertOk()->assertJsonPath('data.voto_secreto', true)->assertJsonPath('data.porcentaje_reforma', '66.67');
    ($this->como)('admin')->putJson('/api/v1/asambleas/configuracion', ['permite_virtual' => true])->assertStatus(422);
});

it('lista las asambleas, filtra por estado y no mezcla condominios', function () {
    ($this->borrador)(['titulo' => 'Primera'])->assertCreated();
    $segunda = ($this->borrador)(['titulo' => 'Segunda'])->json('data.id');
    enCondominio($this->condominio, fn () => Asamblea::whereKey($segunda)->update(['estado' => 'convocada']));

    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Administrador);
    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->postJson('/api/v1/asambleas', [
        'titulo' => 'Ajena', 'tipo' => 'ordinaria', 'modalidad' => 'presencial', 'fecha_hora' => $this->fecha, 'lugar' => 'x',
    ])->assertCreated();

    expect(collect(($this->como)('admin')->getJson('/api/v1/asambleas')->json('data'))->pluck('titulo')->sort()->values()->all())->toBe(['Primera', 'Segunda']);
    expect(collect(($this->como)('admin')->getJson('/api/v1/asambleas?estado=convocada')->json('data'))->pluck('titulo')->all())->toBe(['Segunda']);
    ($this->como)('admin')->getJson('/api/v1/asambleas?estado=inventado')->assertStatus(422);
    // Un id de otro condominio no existe para este
    $ajena = enCondominio($otro, fn () => Asamblea::first()->id);
    ($this->como)('admin')->getJson("/api/v1/asambleas/{$ajena}")->assertNotFound();
});

it('calcula la anticipación de una fecha sin guardar nada', function () {
    $lejos = ($this->como)('admin')->getJson('/api/v1/asambleas/anticipacion?fecha_hora='.urlencode($this->fecha))->assertOk()->json('data');
    expect($lejos)->toMatchArray(['cumple' => true, 'minimo' => 8, 'segunda_convocatoria' => '20:00'])
        ->and($lejos['dias_habiles'])->toBeGreaterThanOrEqual(8);

    $manana = ($this->como)('admin')->getJson('/api/v1/asambleas/anticipacion?fecha_hora='.urlencode(hoyLocal()->addDay()->format('Y-m-d').'T19:00'))->json('data');
    expect($manana['cumple'])->toBeFalse();

    ($this->como)('admin')->getJson('/api/v1/asambleas/anticipacion')->assertStatus(422);
    ($this->como)('admin')->getJson('/api/v1/asambleas/anticipacion?fecha_hora=17/10/2026')->assertStatus(422);
    ($this->como)('residente')->getJson('/api/v1/asambleas/anticipacion?fecha_hora='.urlencode($this->fecha))->assertForbidden();
    expect(enCondominio($this->condominio, fn () => Asamblea::count()))->toBe(0);
});
