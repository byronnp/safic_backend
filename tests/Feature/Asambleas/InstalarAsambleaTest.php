<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaUnidad;
use App\Modules\Asambleas\Models\ConfiguracionAsambleas;
use App\Modules\Plataforma\Models\Condominio;
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
    [, $this->tokenVice] = usuarioConToken($this->condominio, Rol::Vicepresidente);
    [, $this->tokenSecretario] = usuarioConToken($this->condominio, Rol::Secretario);
    // Alícuotas que suman 10: con 6 hay quórum, con 5 no
    [, $this->tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '4.0000']);
    [, , $this->unidadB] = residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '1.0000']);
    [, , $this->unidadC] = residenteDe($this->condominio, 'A-103', 'propietario', ['alicuota' => '5.0000']);
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
        ($this->como)($this->tokenAdmin)->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
        ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$id}/registro/abrir")->assertOk();

        return $id;
    })();
    $this->marcar = fn (int $unidad) => ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/asistencia", ['unidad_id' => $unidad])->assertOk();
    $this->instalar = fn (array $d = ['convocatoria' => 1], ?string $token = null) => ($this->como)($token)->postJson("/api/v1/asambleas/{$this->id}/instalar", $d);
    $this->quorum = fn () => ($this->como)($this->tokenSecretario)->getJson("/api/v1/asambleas/{$this->id}/quorum")->assertOk()->json('data');
});

it('el presidente instala en primera convocatoria con más del 50 % y se congela la foto', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);

    $r = ($this->instalar)()->assertOk();

    expect($r->json('data.estado'))->toBe('instalada')
        ->and($r->json('data.instalacion'))->toMatchArray(['convocatoria' => 1, 'en_subrogacion' => false, 'quorum_alicuota_presente' => '6.0000', 'quorum_alicuota_total' => '10.0000'])
        ->and($r->json('data.puede_instalar'))->toBeFalse();
    enCondominio($this->condominio, function () {
        $foto = AsambleaUnidad::where('asamblea_id', $this->id)->orderBy('codigo')->get();
        expect($foto)->toHaveCount(3)->and($foto->pluck('codigo')->all())->toBe(['A-101', 'A-102', 'A-103'])
            ->and((string) $foto[0]->alicuota)->toBe('4.0000')->and($foto[0]->propietarios[0]['nombre'])->not->toBe('');
    });
});

it('sin quórum no se instala en primera convocatoria; sin abrir el registro tampoco', function () {
    ($this->marcar)($this->unidadC->id);   // 5 de 10: la mitad exacta no alcanza

    ($this->instalar)()->assertStatus(422)->assertJsonPath('error.code', 'SIN_QUORUM');
    expect(enCondominio($this->condominio, fn () => AsambleaUnidad::count()))->toBe(0);

    enCondominio($this->condominio, fn () => Asamblea::whereKey($this->id)->update(['estado' => 'convocada']));
    ($this->instalar)()->assertStatus(409)->assertJsonPath('error.code', 'INSTALACION_NO_DISPONIBLE');
});

it('la segunda convocatoria se instala con los presentes, pasados 60 minutos', function () {
    ($this->marcar)($this->unidadB->id);

    ($this->instalar)(['convocatoria' => 2])->assertStatus(422)->assertJsonPath('error.code', 'SEGUNDA_CONVOCATORIA_NO_ABIERTA');

    enCondominio($this->condominio, fn () => Asamblea::whereKey($this->id)->update(['fecha_hora' => now()->subMinutes(61)]));
    $r = ($this->instalar)(['convocatoria' => 2])->assertOk();
    expect($r->json('data.instalacion.convocatoria'))->toBe(2)->and($r->json('data.instalacion.quorum_alicuota_presente'))->toBe('1.0000');
});

it('la segunda convocatoria necesita al menos un presente', function () {
    enCondominio($this->condominio, fn () => Asamblea::whereKey($this->id)->update(['fecha_hora' => now()->subMinutes(61)]));

    ($this->instalar)(['convocatoria' => 2])->assertStatus(422)->assertJsonPath('error.code', 'SIN_ASISTENTES');
});

it('el vicepresidente instala en subrogación solo si declara al presidente ausente', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);

    ($this->instalar)(['convocatoria' => 1], $this->tokenVice)->assertStatus(422)->assertJsonPath('error.code', 'PRESIDENTE_AUSENTE_REQUERIDO');
    $r = ($this->instalar)(['convocatoria' => 1, 'presidente_ausente' => true], $this->tokenVice)->assertOk();

    expect($r->json('data.instalacion.en_subrogacion'))->toBeTrue();
});

it('solo el presidente y el vicepresidente instalan; el secretario ve la mesa', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);

    foreach ([$this->tokenAdmin, $this->tokenSecretario, $this->tokenA] as $token) {
        ($this->instalar)(['convocatoria' => 1], $token)->assertForbidden();
    }
    ($this->como)($this->tokenSecretario)->getJson("/api/v1/asambleas/{$this->id}/mesa")->assertOk();
    ($this->como)($this->tokenA)->getJson("/api/v1/asambleas/{$this->id}/mesa")->assertForbidden();
    ($this->como)($this->tokenPresidente)->getJson('/api/v1/asambleas')->assertOk()->assertJsonCount(1, 'data');
});

it('una asamblea se instala una sola vez', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);
    ($this->instalar)()->assertOk();

    ($this->instalar)()->assertStatus(409)->assertJsonPath('error.message', 'Esta asamblea ya se instaló.');
});

it('después de instalar, ni la mora ni las reglas cambian el quórum: rige la foto', function () {
    cuotaDe($this->condominio, $this->unidadC, 3);   // C debe cuotas al instalar, pero por defecto cuenta para el quórum
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);
    ($this->instalar)()->assertOk();

    expect(($this->quorum)())->toMatchArray(['alicuota_total' => '10.00', 'alicuota_presente' => '6.00', 'mora_cuenta_para_quorum' => true]);

    // Más tarde el condominio cambia la regla y C paga: la asamblea sigue con lo que había al instalar
    enCondominio($this->condominio, fn () => ConfiguracionAsambleas::create(['mora_cuenta_para_quorum' => false]));
    enCondominio($this->condominio, fn () => DB::table('cuotas')->where('unidad_id', $this->unidadC->id)->update(['pagado' => DB::raw('monto')]));
    expect(($this->quorum)())->toMatchArray(['alicuota_total' => '10.00', 'alicuota_presente' => '6.00']);
    $lista = collect(($this->como)($this->tokenSecretario)->getJson("/api/v1/asambleas/{$this->id}/asistencia")->json('data.unidades'))->firstWhere('codigo', 'A-103');
    expect($lista['en_mora'])->toBeTrue();
});

it('el que llega tarde se marca contra la foto: una unidad nueva no entra', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);
    ($this->instalar)()->assertOk();

    ($this->marcar)($this->unidadA->id);   // llegó tarde, estaba en el padrón al instalar
    expect(($this->quorum)()['alicuota_presente'])->toBe('10.00');

    [, , $nueva] = residenteDe($this->condominio, 'Z-1', 'propietario', ['alicuota' => '1.0000']);
    ($this->como)($this->tokenSecretario)->postJson("/api/v1/asambleas/{$this->id}/asistencia", ['unidad_id' => $nueva->id])->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_SIN_DERECHO');
});

it('instalada, ya no se reciben poderes', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);
    ($this->instalar)()->assertOk();

    ($this->como)($this->tokenA)->post("/api/v1/asambleas/{$this->id}/poderes", [
        'unidad_id' => $this->unidadA->id, 'tipo' => 'carta', 'representante_nombre' => 'Marta Vera Cano', 'representante_documento' => '1710034065',
        'archivo' => UploadedFile::fake()->create('poder.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'PODERES_CERRADOS');
});

it('la mesa trae el quórum, el orden del día y cómo se instaló', function () {
    ($this->marcar)($this->unidadC->id);
    ($this->marcar)($this->unidadB->id);

    $antes = ($this->como)()->getJson("/api/v1/asambleas/{$this->id}/mesa")->assertOk()->json('data');
    expect($antes)->toMatchArray(['estado' => 'en_registro', 'puede_instalar' => true, 'instalacion' => null])
        ->and($antes['puntos'])->toHaveCount(1)->and($antes['quorum']['hay_quorum'])->toBeTrue()->and($antes['condominio'])->not->toBe('');

    ($this->instalar)()->assertOk();
    expect(($this->como)()->getJson("/api/v1/asambleas/{$this->id}/mesa")->json('data.instalacion.instalada_por'))->not->toBeNull();
});

it('la mesa de un condominio no se ve en otro', function () {
    $otro = Condominio::factory()->create();
    [, $tokenOtro] = usuarioConToken($otro, Rol::Presidente);

    cambiarDeUsuario();
    $this->withToken($tokenOtro)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson("/api/v1/asambleas/{$this->id}/mesa")->assertNotFound();
});
