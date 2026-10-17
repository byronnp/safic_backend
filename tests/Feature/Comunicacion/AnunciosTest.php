<?php

use App\Core\Permissions\Rol;
use App\Modules\Comunicacion\Mail\AnuncioMail;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    Mail::fake();

    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle']);
    $this->torreA = enCondominio($this->condominio, fn () => Bloque::create(['nombre' => 'Torre A', 'orden' => 1]));
    $this->torreB = enCondominio($this->condominio, fn () => Bloque::create(['nombre' => 'Torre B', 'orden' => 2]));
    [$this->userA, $tokenA, $this->unidadA] = residenteDe($this->condominio, 'A-101', 'propietario', ['bloque_id' => $this->torreA->id]);
    [$this->userB, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-201', 'propietario', ['bloque_id' => $this->torreB->id]);
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'B-VACIA', 'bloque_id' => $this->torreB->id]));   // sin nadie viviendo
    [$this->adminUser, $tokenAdm] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->resA = ($this->como)($tokenA);
    $this->resB = ($this->como)($tokenB);
    $this->admin = ($this->como)($tokenAdm);
    $this->tesorero = ($this->como)($tokenT);

    $this->publicar = fn (array $extra = []) => ($this->admin)()->post('/api/v1/comunicacion/anuncios', $extra + [
        'titulo' => 'Corte de agua programado', 'mensaje' => 'El martes de 09:00 a 13:00 no habrá agua.', 'destino_tipo' => 'todos',
    ], ['Accept' => 'application/json']);
});

it('publica a todo el condominio: llega a las unidades con residentes y avisa por correo', function () {
    $r = ($this->publicar)()->assertCreated();

    expect(enCondominio($this->condominio, fn () => AnuncioDestinatario::where('anuncio_id', $r->json('data.id'))->count()))->toBe(2);   // la vacía no cuenta
    Mail::assertQueued(AnuncioMail::class, 2);
    Mail::assertQueued(AnuncioMail::class, fn ($m) => $m->titulo === 'Corte de agua programado' && $m->recordatorio === false);
});

it('publica solo a un bloque o a unidades concretas', function () {
    ($this->publicar)(['titulo' => 'Solo Torre A', 'destino_tipo' => 'bloque', 'bloque_id' => $this->torreA->id])->assertCreated();
    ($this->publicar)(['titulo' => 'Solo B-201', 'destino_tipo' => 'unidades', 'unidades' => ['b-201']])->assertCreated();

    $avisosA = collect(($this->resA)()->getJson('/api/v1/mis-avisos')->json('data.avisos'))->pluck('titulo')->all();
    $avisosB = collect(($this->resB)()->getJson('/api/v1/mis-avisos')->json('data.avisos'))->pluck('titulo')->all();
    expect($avisosA)->toBe(['Solo Torre A'])->and($avisosB)->toBe(['Solo B-201']);
});

it('el alcance dice cuántas unidades y personas con cuenta recibirían el anuncio', function () {
    $r = ($this->admin)()->getJson('/api/v1/comunicacion/anuncios/alcance?destino_tipo=bloque&bloque_id='.$this->torreB->id)->assertOk();
    expect($r->json('data'))->toBe(['destino' => 'Torre B', 'unidades' => 1, 'personas_con_cuenta' => 1]);

    $t = ($this->admin)()->getJson('/api/v1/comunicacion/anuncios/alcance?destino_tipo=todos')->json('data');
    expect($t['unidades'])->toBe(2)->and($t['destino'])->toBe('Todo el condominio');
});

it('valida el destino: unidades inexistentes, destino sin residentes, fecha pasada', function () {
    ($this->publicar)(['destino_tipo' => 'unidades', 'unidades' => ['ZZ-9']])->assertStatus(422)->assertJsonPath('error.code', 'UNIDADES_NO_ENCONTRADAS');
    ($this->publicar)(['destino_tipo' => 'unidades', 'unidades' => ['B-VACIA']])->assertStatus(422)->assertJsonPath('error.code', 'SIN_DESTINATARIOS');
    ($this->publicar)(['destino_tipo' => 'bloque', 'bloque_id' => 99999])->assertStatus(422)->assertJsonPath('error.code', 'BLOQUE_NO_ENCONTRADO');
    ($this->publicar)(['expira' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'EXPIRA_PASADO');
    ($this->publicar)(['titulo' => 'Ab'])->assertStatus(422);
    ($this->publicar)(['destino_tipo' => 'bloque'])->assertStatus(422);
    expect(enCondominio($this->condominio, fn () => Anuncio::count()))->toBe(0);
});

it('el residente lee y confirma; la administración ve el porcentaje', function () {
    $id = ($this->publicar)(['confirmacion' => true])->json('data.id');

    $antes = ($this->resA)()->getJson('/api/v1/mis-avisos')->json('data');
    expect($antes['no_leidos'])->toBe(1)->and($antes['avisos'][0])->toMatchArray(['leido' => false, 'confirmado' => false, 'requiere_confirmacion' => true]);

    ($this->resA)()->postJson("/api/v1/mis-avisos/{$id}/leer")->assertOk();
    $lista = ($this->admin)()->getJson('/api/v1/comunicacion/anuncios')->json('data');
    expect($lista[0])->toMatchArray(['unidades' => 2, 'leidas' => 1, 'confirmadas' => 0, 'porcentaje_leido' => 50]);

    ($this->resA)()->postJson("/api/v1/mis-avisos/{$id}/confirmar")->assertOk();
    ($this->resA)()->postJson("/api/v1/mis-avisos/{$id}/confirmar")->assertOk();   // idempotente
    $despues = ($this->resA)()->getJson('/api/v1/mis-avisos')->json('data');
    expect($despues['no_leidos'])->toBe(0)->and($despues['avisos'][0])->toMatchArray(['leido' => true, 'confirmado' => true])
        ->and(($this->admin)()->getJson('/api/v1/comunicacion/anuncios')->json('data.0.confirmadas'))->toBe(1);
    expect(enCondominio($this->condominio, fn () => AnuncioDestinatario::where('unidad_id', $this->unidadA->id)->first()->confirmado_por))->toBe($this->userA->id);
});

it('confirmar exige que el anuncio lo pida; uno que no llegó a mi unidad no existe', function () {
    $sinPedir = ($this->publicar)()->json('data.id');
    $soloA = ($this->publicar)(['titulo' => 'Solo A', 'destino_tipo' => 'bloque', 'bloque_id' => $this->torreA->id, 'confirmacion' => true])->json('data.id');

    ($this->resA)()->postJson("/api/v1/mis-avisos/{$sinPedir}/confirmar")->assertStatus(409)->assertJsonPath('error.code', 'NO_PIDE_CONFIRMACION');
    ($this->resB)()->postJson("/api/v1/mis-avisos/{$soloA}/leer")->assertNotFound();
    ($this->resB)()->postJson("/api/v1/mis-avisos/{$soloA}/confirmar")->assertNotFound();
});

it('un anuncio expirado ya no se ve ni se confirma, pero sigue en la lista de la administración', function () {
    $id = ($this->publicar)(['confirmacion' => true, 'expira' => hoyLocal()->addDay()->toDateString()])->json('data.id');
    $this->travelTo(now()->addDays(3));
    // Pasado el tiempo los tokens de la prueba vencieron: se emiten nuevos
    $residente = ($this->como)(auth('api')->tokenById($this->userA->id));
    $admin = ($this->como)(auth('api')->tokenById($this->adminUser->id));

    expect($residente()->getJson('/api/v1/mis-avisos')->json('data.avisos'))->toBe([]);
    $residente()->postJson("/api/v1/mis-avisos/{$id}/confirmar")->assertStatus(409)->assertJsonPath('error.code', 'ANUNCIO_EXPIRADO');
    $admin()->postJson("/api/v1/comunicacion/anuncios/{$id}/reenviar")->assertStatus(409)->assertJsonPath('error.code', 'ANUNCIO_EXPIRADO');
    expect($admin()->getJson('/api/v1/comunicacion/anuncios')->json('data.0'))->toMatchArray(['vigente' => false]);
});

it('los fijados van primero', function () {
    ($this->publicar)(['titulo' => 'Viejo fijado', 'fijar' => true])->assertCreated();
    ($this->publicar)(['titulo' => 'Nuevo normal'])->assertCreated();

    expect(collect(($this->resA)()->getJson('/api/v1/mis-avisos')->json('data.avisos'))->pluck('titulo')->all())->toBe(['Viejo fijado', 'Nuevo normal'])
        ->and(collect(($this->admin)()->getJson('/api/v1/comunicacion/anuncios')->json('data'))->pluck('titulo')->all())->toBe(['Viejo fijado', 'Nuevo normal']);
});

it('reenviar avisa solo a las unidades pendientes', function () {
    $id = ($this->publicar)(['confirmacion' => true])->json('data.id');
    ($this->resA)()->postJson("/api/v1/mis-avisos/{$id}/confirmar")->assertOk();

    $r = ($this->admin)()->postJson("/api/v1/comunicacion/anuncios/{$id}/reenviar")->assertOk();

    expect($r->json('data.unidades'))->toBe(1);
    Mail::assertQueued(AnuncioMail::class, fn ($m) => $m->recordatorio === true);
});

it('la constancia en PDF lista quién confirmó y quién falta', function () {
    $id = ($this->publicar)(['confirmacion' => true])->json('data.id');
    ($this->resA)()->postJson("/api/v1/mis-avisos/{$id}/confirmar")->assertOk();

    $r = ($this->admin)()->get("/api/v1/comunicacion/anuncios/{$id}/constancia")->assertOk();

    expect($r->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($r->headers->get('Content-Disposition'))->toContain('constancia-corte-de-agua-programado.pdf')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');
});

it('el adjunto se guarda y el residente lo ve con enlace temporal', function () {
    ($this->publicar)(['adjunto' => UploadedFile::fake()->create('aviso.pdf', 100, 'application/pdf')])->assertCreated();

    $a = ($this->resA)()->getJson('/api/v1/mis-avisos')->json('data.avisos.0');
    expect($a['adjunto_nombre'])->toBe('aviso.pdf')->and($a['adjunto_url'])->not->toBeNull();
    ($this->publicar)(['adjunto' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);
});

it('un condominio no ve los anuncios de otro y los permisos se respetan', function () {
    ($this->publicar)()->assertCreated();
    $otro = Condominio::factory()->create();
    [, $tokenO] = usuarioConToken($otro, Rol::Administrador);
    cambiarDeUsuario();

    expect($this->withToken($tokenO)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/comunicacion/anuncios')->json('data'))->toBe([]);
    ($this->tesorero)()->getJson('/api/v1/comunicacion/anuncios')->assertForbidden();
    ($this->resA)()->getJson('/api/v1/comunicacion/anuncios')->assertForbidden();
    ($this->admin)()->getJson('/api/v1/mis-avisos')->assertOk();   // el administrador también puede ser residente
});
