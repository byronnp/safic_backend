<?php

use App\Core\Permissions\Rol;
use App\Modules\Garita\Actions\AnonimizarGaritaAction;
use App\Modules\Garita\Actions\RecordarPaquetesAction;
use App\Modules\Garita\Mail\PaqueteEnGaritaMail;
use App\Modules\Garita\Models\Paquete;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle']);
    [$this->user, $tokenR, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    [, $tokenA] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($tokenR);
    $this->vecino = ($this->como)($tokenB);
    $this->guardia = ($this->como)($tokenG);
    $this->admin = ($this->como)($tokenA);
    $this->tesorero = ($this->como)($tokenT);

    $this->recibir = fn (array $extra = []) => ($this->guardia)()->post('/api/v1/garita/paquetes', $extra + [
        'unidad_codigo' => 'a-102', 'empresa' => 'Servientrega', 'descripcion' => 'Caja mediana', 'foto' => UploadedFile::fake()->image('paquete.jpg', 400, 300),
    ], ['Accept' => 'application/json']);
    $this->entregar = fn (int $id, array $extra = []) => ($this->guardia)()->post("/api/v1/garita/paquetes/{$id}/entregar", $extra + [
        'firma' => UploadedFile::fake()->image('firma.png', 300, 100),
    ], ['Accept' => 'application/json']);
    $this->ocupanteId = fn () => ($this->guardia)()->getJson('/api/v1/garita/paquetes')->json('data.en_garita.0.ocupantes.0.id');
});

it('el guardia recibe un paquete con su foto y se avisa por correo a quienes viven en la unidad', function () {
    $r = ($this->recibir)()->assertCreated();

    expect(enCondominio($this->condominio, fn () => Paquete::find($r->json('data.id'))->estado))->toBe('en_garita');
    Mail::assertQueued(PaqueteEnGaritaMail::class, fn ($m) => $m->empresa === 'Servientrega' && $m->unidad === 'A-102' && $m->recordatorio === false);
    Storage::disk('local')->assertExists(enCondominio($this->condominio, fn () => Paquete::first()->foto_ruta));
});

it('exige foto de la imagen, empresa y una unidad que exista', function () {
    ($this->recibir)(['foto' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')])->assertStatus(422);
    ($this->recibir)(['foto' => null])->assertStatus(422);
    ($this->recibir)(['empresa' => ''])->assertStatus(422);
    ($this->recibir)(['unidad_codigo' => 'ZZZ-9'])->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_NO_ENCONTRADA');
    expect(enCondominio($this->condominio, fn () => Paquete::count()))->toBe(0);
});

it('la lista de la garita trae los paquetes con sus ocupantes y marca los atrasados', function () {
    ($this->recibir)()->assertCreated();
    $viejo = enCondominio($this->condominio, fn () => Paquete::create(['unidad_id' => $this->unidadB->id, 'empresa' => 'Mercado Libre', 'foto_ruta' => 'x/y.jpg', 'estado' => 'en_garita', 'recibido_en' => now()->subDays(9)]));

    $r = ($this->guardia)()->getJson('/api/v1/garita/paquetes')->assertOk();
    $filas = collect($r->json('data.en_garita'));

    expect($filas->pluck('empresa')->all())->toBe(['Mercado Libre', 'Servientrega'])
        ->and($filas->firstWhere('id', $viejo->id))->toMatchArray(['atrasado' => true, 'unidad' => 'B-9'])
        ->and($filas->firstWhere('empresa', 'Servientrega'))->toMatchArray(['atrasado' => false, 'unidad' => 'A-102'])
        ->and($filas->firstWhere('empresa', 'Servientrega')['ocupantes'])->toHaveCount(1)
        ->and($filas->firstWhere('empresa', 'Servientrega')['foto_url'])->not->toBeNull();
});

it('entrega a un ocupante vigente con su firma y queda en entregados de hoy', function () {
    $id = ($this->recibir)()->json('data.id');
    $ocupante = ($this->ocupanteId)();

    ($this->entregar)($id, ['ocupante_id' => $ocupante])->assertOk();

    $p = enCondominio($this->condominio, fn () => Paquete::find($id));
    expect($p->estado)->toBe('entregado')->and($p->retira_tipo)->toBe('ocupante')->and($p->retira_nombre)->not->toBeEmpty();
    Storage::disk('local')->assertExists($p->firma_ruta);
    $r = ($this->guardia)()->getJson('/api/v1/garita/paquetes')->json('data');
    expect($r['en_garita'])->toBe([])->and($r['entregados_hoy'][0])->toMatchArray(['empresa' => 'Servientrega', 'retira_nombre' => $p->retira_nombre]);
    ($this->entregar)($id, ['ocupante_id' => $ocupante])->assertStatus(409)->assertJsonPath('error.code', 'PAQUETE_YA_ENTREGADO');
});

it('entrega a una persona autorizada por el residente, escribiendo su nombre', function () {
    $id = ($this->recibir)()->json('data.id');

    ($this->entregar)($id, ['autorizado' => 'Sofía Arteaga'])->assertOk();

    expect(enCondominio($this->condominio, fn () => Paquete::find($id))->only(['retira_tipo', 'retira_nombre']))->toBe(['retira_tipo' => 'autorizado', 'retira_nombre' => 'Sofía Arteaga']);
});

it('pide exactamente quién retira, una firma PNG y un ocupante que siga viviendo ahí', function () {
    $id = ($this->recibir)()->json('data.id');
    $ocupante = ($this->ocupanteId)();

    ($this->entregar)($id)->assertStatus(422)->assertJsonPath('error.code', 'RETIRA_NO_INDICADO');
    ($this->entregar)($id, ['ocupante_id' => $ocupante, 'autorizado' => 'Otra Persona'])->assertStatus(422)->assertJsonPath('error.code', 'RETIRA_NO_INDICADO');
    ($this->entregar)($id, ['ocupante_id' => 999999])->assertStatus(422)->assertJsonPath('error.code', 'OCUPANTE_NO_VIGENTE');
    ($this->entregar)($id, ['ocupante_id' => $ocupante, 'firma' => null])->assertStatus(422);
    ($this->entregar)($id, ['ocupante_id' => $ocupante, 'firma' => UploadedFile::fake()->image('f.jpg')])->assertStatus(422);
    expect(enCondominio($this->condominio, fn () => Paquete::find($id)->estado))->toBe('en_garita');
});

it('el residente ve solo los paquetes de su unidad', function () {
    ($this->recibir)()->assertCreated();
    ($this->recibir)(['unidad_codigo' => 'B-9', 'empresa' => 'Del vecino'])->assertCreated();

    $r = ($this->residente)()->getJson('/api/v1/mis-paquetes')->assertOk();

    expect(collect($r->json('data.en_garita'))->pluck('empresa')->all())->toBe(['Servientrega'])
        ->and($r->json('data.entregados'))->toBe([])
        ->and(json_encode($r->json()))->not->toContain('foto_url');
});

it('el recordatorio avisa de los paquetes con 7 días o más, una vez por semana', function () {
    enCondominio($this->condominio, fn () => Paquete::create(['unidad_id' => $this->unidad->id, 'empresa' => 'Amazon', 'foto_ruta' => 'x/y.jpg', 'estado' => 'en_garita', 'recibido_en' => now()->subDays(8)]));
    enCondominio($this->condominio, fn () => Paquete::create(['unidad_id' => $this->unidad->id, 'empresa' => 'Reciente', 'foto_ruta' => 'x/z.jpg', 'estado' => 'en_garita', 'recibido_en' => now()->subDays(2)]));
    $recordar = fn () => enCondominio($this->condominio, fn () => app(RecordarPaquetesAction::class)->execute());

    expect($recordar())->toBe(1)->and($recordar())->toBe(0);
    Mail::assertQueued(PaqueteEnGaritaMail::class, fn ($m) => $m->recordatorio === true && $m->empresa === 'Amazon');

    $this->travelTo(now()->addDays(8));
    expect($recordar())->toBe(2);
    $this->artisan('garita:recordar-paquetes')->assertSuccessful();
});

it('a los 12 meses se borra quién retiró y su firma', function () {
    $id = ($this->recibir)()->json('data.id');
    ($this->entregar)($id, ['autorizado' => 'Sofía Arteaga'])->assertOk();
    $ruta = enCondominio($this->condominio, fn () => Paquete::find($id)->firma_ruta);
    enCondominio($this->condominio, fn () => Paquete::whereKey($id)->update(['entregado_en' => now()->subMonths(13)]));

    $r = enCondominio($this->condominio, fn () => app(AnonimizarGaritaAction::class)->execute());

    expect($r['paquetes'])->toBe(1);
    $p = enCondominio($this->condominio, fn () => Paquete::find($id));
    expect($p->retira_nombre)->toBe('Visitante anonimizado')->and($p->firma_ruta)->toBeNull()->and($p->unidad_id)->toBe($this->unidad->id);
    Storage::disk('local')->assertMissing($ruta);
});

it('solo el guardia gestiona paquetes y solo el residente ve los suyos', function () {
    ($this->residente)()->getJson('/api/v1/garita/paquetes')->assertForbidden();
    ($this->tesorero)()->post('/api/v1/garita/paquetes', [], ['Accept' => 'application/json'])->assertForbidden();
    ($this->guardia)()->getJson('/api/v1/mis-paquetes')->assertForbidden();
    ($this->admin)()->getJson('/api/v1/garita/paquetes')->assertOk();
});
