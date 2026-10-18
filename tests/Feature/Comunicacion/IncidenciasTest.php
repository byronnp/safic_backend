<?php

use App\Core\Permissions\Rol;
use App\Modules\Comunicacion\Actions\CerrarIncidenciasResueltasAction;
use App\Modules\Comunicacion\Mail\IncidenciaMail;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create();
    [$this->userA, $tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    [$this->guardiaUser, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    [$this->adminUser, $tokenAdm] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->resA = ($this->como)($tokenA);
    $this->resB = ($this->como)($tokenB);
    $this->guardia = ($this->como)($tokenG);
    $this->admin = ($this->como)($tokenAdm);
    $this->tesorero = ($this->como)($tokenT);

    $this->reportar = fn (array $extra = [], $como = null) => ($como ?? $this->resA)()->post('/api/v1/mis-incidencias', $extra + [
        'titulo' => 'Fuga de agua en el parqueadero', 'descripcion' => 'Gotea desde el techo desde ayer.', 'categoria' => 'mantenimiento', 'unidad_id' => $this->unidadA->id,
    ], ['Accept' => 'application/json']);
});

it('el residente reporta con fotos y recibe un número consecutivo', function () {
    $r = ($this->reportar)(['fotos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')]])->assertCreated();
    $s = ($this->reportar)(['titulo' => 'Otra'])->assertCreated();

    expect($r->json('data.numero'))->toBe(1)->and($s->json('data.numero'))->toBe(2);
    $mias = ($this->resA)()->getJson('/api/v1/mis-incidencias')->assertOk()->json('data');
    $primera = collect($mias)->firstWhere('numero', 1);
    expect($primera)->toMatchArray(['estado' => 'abierto', 'prioridad' => 'media', 'unidad' => 'A-102', 'categoria' => 'mantenimiento'])
        ->and($primera['fotos'])->toHaveCount(2)->and($primera['fotos'][0]['url'])->not->toBeNull();
});

it('valida título, categoría, fotos y unidad', function () {
    ($this->reportar)(['titulo' => 'Ab'])->assertStatus(422);
    ($this->reportar)(['categoria' => 'ruido'])->assertStatus(422);
    ($this->reportar)(['fotos' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 4))])->assertStatus(422);
    ($this->reportar)(['fotos' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]])->assertStatus(422);
    ($this->reportar)(['unidad_id' => $this->unidadB->id])->assertStatus(403)->assertJsonPath('error.code', 'UNIDAD_NO_ES_SUYA');
    expect(enCondominio($this->condominio, fn () => Incidencia::count()))->toBe(0);
});

it('el guardia reporta un problema de las áreas comunes, sin unidad', function () {
    ($this->reportar)(['unidad_id' => null, 'titulo' => 'Luz dañada en el parqueadero'], $this->guardia)->assertCreated();

    $t = ($this->admin)()->getJson('/api/v1/comunicacion/incidencias')->json('data.incidencias');
    expect($t)->toHaveCount(1)->and($t[0])->toMatchArray(['unidad' => null, 'estado' => 'abierto']);
    expect(($this->admin)()->getJson('/api/v1/comunicacion/incidencias/'.$t[0]['id'])->json('data.autor'))->toBe($this->guardiaUser->name);
});

it('la administración ve el tablero, el detalle con notas internas y ajusta la prioridad', function () {
    $id = ($this->reportar)()->json('data.id');

    ($this->admin)()->patchJson("/api/v1/comunicacion/incidencias/{$id}", ['prioridad' => 'alta'])->assertOk()->assertJsonPath('data.prioridad', 'alta');
    ($this->admin)()->patchJson("/api/v1/comunicacion/incidencias/{$id}", ['prioridad' => 'urgente'])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/comentarios", ['texto' => 'Ya coordinamos con el técnico.'])->assertCreated();
    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/comentarios", ['texto' => 'Cotización $85. No visible.', 'interna' => true])->assertCreated();

    $adm = ($this->admin)()->getJson("/api/v1/comunicacion/incidencias/{$id}")->json('data');
    expect(collect($adm['comentarios'])->pluck('interna')->all())->toBe([false, true])->and($adm['prioridad'])->toBe('alta');
    $res = collect(($this->resA)()->getJson('/api/v1/mis-incidencias')->json('data'))->firstWhere('id', $id);
    expect(collect($res['comentarios'])->pluck('texto')->all())->toBe(['Ya coordinamos con el técnico.'])
        ->and(json_encode($res))->not->toContain('Cotización');
});

it('un comentario público avisa al residente; una nota interna no', function () {
    $id = ($this->reportar)()->json('data.id');

    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/comentarios", ['texto' => 'Nota privada', 'interna' => true])->assertCreated();
    Mail::assertNothingQueued();

    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/comentarios", ['texto' => 'Viene mañana a las 09:00.'])->assertCreated();
    Mail::assertQueued(IncidenciaMail::class, fn ($m) => $m->detalle === 'Viene mañana a las 09:00.' && str_contains($m->incidencia, 'Fuga de agua'));
});

it('el flujo de estados: abierto, en proceso, resuelto; saltos no permitidos se rechazan', function () {
    $id = ($this->reportar)()->json('data.id');
    $estado = fn (string $e) => ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/estado", ['estado' => $e]);

    $estado('cerrado')->assertStatus(409)->assertJsonPath('error.code', 'TRANSICION_NO_PERMITIDA');
    $estado('en_proceso')->assertOk()->assertJsonPath('data.estado', 'en_proceso');
    $estado('abierto')->assertStatus(409);
    $estado('resuelto')->assertOk();
    $estado('inexistente')->assertStatus(422);
    Mail::assertQueued(IncidenciaMail::class, fn ($m) => $m->titulo === 'Tu incidencia fue resuelta');
});

it('el tiempo promedio de respuesta cuenta desde el reporte hasta la primera respuesta pública', function () {
    $a = ($this->reportar)()->json('data.id');
    ($this->reportar)(['titulo' => 'Sin atender'])->assertCreated();

    expect(($this->admin)()->getJson('/api/v1/comunicacion/incidencias')->json('data.tiempo_promedio_horas'))->toBeNull();

    enCondominio($this->condominio, fn () => Incidencia::whereKey($a)->update(['created_at' => now()->subHours(6)]));
    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$a}/comentarios", ['texto' => 'Revisando.'])->assertCreated();

    expect(($this->admin)()->getJson('/api/v1/comunicacion/incidencias')->json('data.tiempo_promedio_horas'))->toEqual(6);
});

it('el residente confirma que se solucionó (se cierra) o la reabre (vuelve a en proceso)', function () {
    $id = ($this->reportar)()->json('data.id');
    $otra = ($this->reportar)(['titulo' => 'Otra'])->json('data.id');
    foreach ([$id, $otra] as $i) {
        ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$i}/estado", ['estado' => 'resuelto'])->assertOk();
    }

    ($this->resA)()->postJson("/api/v1/mis-incidencias/{$id}/confirmar")->assertOk();
    ($this->resA)()->postJson("/api/v1/mis-incidencias/{$otra}/reabrir", ['texto' => 'Sigue goteando.'])->assertOk();

    $mias = collect(($this->resA)()->getJson('/api/v1/mis-incidencias')->json('data'));
    expect($mias->firstWhere('id', $id)['estado'])->toBe('cerrado')->and($mias->firstWhere('id', $id)['cierre'])->toBe('residente')
        ->and($mias->firstWhere('id', $otra)['estado'])->toBe('en_proceso')
        ->and(collect($mias->firstWhere('id', $otra)['comentarios'])->pluck('texto')->all())->toBe(['Sigue goteando.']);
    ($this->resA)()->postJson("/api/v1/mis-incidencias/{$id}/reabrir")->assertStatus(409)->assertJsonPath('error.code', 'INCIDENCIA_NO_RESUELTA');
    ($this->resA)()->postJson("/api/v1/mis-incidencias/{$id}/comentarios", ['texto' => 'Hola'])->assertStatus(409)->assertJsonPath('error.code', 'INCIDENCIA_CERRADA');
});

it('una resuelta sin respuesta en 7 días se cierra sola', function () {
    $id = ($this->reportar)()->json('data.id');
    $reciente = ($this->reportar)(['titulo' => 'Reciente'])->json('data.id');
    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$id}/estado", ['estado' => 'resuelto'])->assertOk();
    ($this->admin)()->postJson("/api/v1/comunicacion/incidencias/{$reciente}/estado", ['estado' => 'resuelto'])->assertOk();
    enCondominio($this->condominio, fn () => Incidencia::whereKey($id)->update(['resuelta_en' => now()->subDays(8)]));

    $n = enCondominio($this->condominio, fn () => app(CerrarIncidenciasResueltasAction::class)->execute());

    expect($n)->toBe(1);
    $filas = enCondominio($this->condominio, fn () => Incidencia::pluck('estado', 'id')->all());
    expect($filas[$id])->toBe('cerrado')->and($filas[$reciente])->toBe('resuelto')
        ->and(enCondominio($this->condominio, fn () => Incidencia::find($id)->cierre))->toBe('automatico');
    $this->artisan('comunicacion:cerrar-incidencias')->assertSuccessful();
});

it('un residente no ve ni toca las incidencias de otra unidad', function () {
    $id = ($this->reportar)()->json('data.id');

    expect(($this->resB)()->getJson('/api/v1/mis-incidencias')->json('data'))->toBe([]);
    ($this->resB)()->postJson("/api/v1/mis-incidencias/{$id}/comentarios", ['texto' => 'Hola'])->assertNotFound();
    ($this->resB)()->postJson("/api/v1/mis-incidencias/{$id}/confirmar")->assertNotFound();
});

it('los permisos se respetan y otro condominio no ve las incidencias', function () {
    ($this->reportar)()->assertCreated();
    ($this->tesorero)()->getJson('/api/v1/comunicacion/incidencias')->assertForbidden();
    ($this->resA)()->getJson('/api/v1/comunicacion/incidencias')->assertForbidden();
    ($this->guardia)()->getJson('/api/v1/comunicacion/incidencias')->assertForbidden();
    ($this->tesorero)()->getJson('/api/v1/mis-incidencias')->assertForbidden();

    $otro = Condominio::factory()->create();
    [, $tokenO] = usuarioConToken($otro, Rol::Administrador);
    cambiarDeUsuario();
    expect($this->withToken($tokenO)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/comunicacion/incidencias')->json('data.incidencias'))->toBe([]);
});
