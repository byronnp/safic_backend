<?php

use App\Core\Permissions\Rol;
use App\Core\Support\SequenceService;
use App\Modules\Finanzas\Mail\PagoAprobadoMail;
use App\Modules\Finanzas\Mail\PagoRechazadoMail;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);

    $this->condominio = Condominio::factory()->create(['codigo' => 'JV', 'nombre' => 'Jardines del Valle']);
    [, $this->adminToken] = usuarioConToken($this->condominio);
    $this->admin = function () {
        cambiarDeUsuario();

        return $this->withToken($this->adminToken)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    enCondominio($this->condominio, fn () => CuentaBancaria::create(['banco' => 'Banco Pichincha', 'tipo' => 'corriente', 'numero' => '2100123456', 'titular' => 'Condominio', 'es_principal' => true]));

    [$this->user, $this->tokenResidente, $this->unidad] = residenteDe($this->condominio, 'A-102');
    $this->c1 = cuotaDe($this->condominio, $this->unidad, 2);
    $this->c2 = cuotaDe($this->condominio, $this->unidad, 1);
    $this->c3 = cuotaDe($this->condominio, $this->unidad, 0);

    $this->enviar = function (array $cuotas, string $numero = '1001') {
        cambiarDeUsuario();
        $r = $this->withToken($this->tokenResidente)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->post('/api/v1/mi-cuenta/pagos', [
            'unidad_id' => $this->unidad->id, 'cuotas' => $cuotas, 'numero_comprobante' => $numero, 'comprobante' => UploadedFile::fake()->image('c.jpg', 600, 800),
        ], ['Accept' => 'application/json'])->assertCreated();

        return $r->json('data.pago_id');
    };
    $this->saldos = fn () => enCondominio($this->condominio, fn () => Cuota::query()->orderBy('periodo')->pluck('pagado')->map(fn ($v) => (string) $v)->all());
    $this->favor = fn () => collect((($this->residente)())->getJson('/api/v1/mi-cuenta')->json('data.unidades'))->first()['saldo_favor'];
    $this->residente = function () {
        cambiarDeUsuario();

        return $this->withToken($this->tokenResidente)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
});

function sesionConRol(Condominio $condominio, Rol $rol, bool $conDobleFactor = false): array
{
    [$u, $token] = usuarioConToken($condominio, $rol);
    if ($conDobleFactor) {
        $u->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    return [$u, $token];
}

it('aprueba un pago: aplica a las cuotas, numera el recibo y avisa al residente', function () {
    $id = ($this->enviar)([$this->c1->id, $this->c2->id]);

    $r = ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk()->assertJsonPath('message', 'Pago aprobado.');

    expect($r->json('data'))->toMatchArray(['estado' => 'aprobado', 'recibo' => '000001', 'monto' => '160.00'])
        ->and(($this->saldos)())->toBe(['80.00', '80.00', '0.00'])
        ->and(($this->favor)())->toBe('0.00');
    $pago = enCondominio($this->condominio, fn () => Pago::query()->findOrFail($id));
    expect($pago->revisado_por)->not->toBeNull()
        ->and(enCondominio($this->condominio, fn () => DB::table('pago_aplicaciones')->where('pago_id', $id)->count()))->toBe(2);
    Mail::assertQueued(PagoAprobadoMail::class, fn ($m) => $m->hasTo($this->user->email) && $m->recibo === '000001');
});

it('lo que sobra queda a favor y se descuenta del siguiente pago', function () {
    $id = ($this->enviar)([$this->c1->id]);

    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar", ['monto_recibido' => '100.00'])->assertOk()->assertJsonPath('data.diferencia', '20.00');

    expect(($this->saldos)())->toBe(['80.00', '0.00', '0.00'])->and(($this->favor)())->toBe('20.00');
    $r = (($this->residente)())->post('/api/v1/mi-cuenta/pagos', [
        'unidad_id' => $this->unidad->id, 'cuotas' => [$this->c2->id], 'numero_comprobante' => '1002', 'comprobante' => UploadedFile::fake()->image('c.jpg', 600, 800),
    ], ['Accept' => 'application/json'])->assertCreated();
    expect($r->json('data.monto'))->toBe('60.00');
});

it('un pago más grande se aplica a todas las cuotas que alcance, incluso las que no declaró', function () {
    $id = ($this->enviar)([$this->c1->id]);

    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar", ['monto_recibido' => '170.00'])->assertOk();

    expect(($this->saldos)())->toBe(['80.00', '80.00', '0.00'])->and(($this->favor)())->toBe('10.00');
});

it('la tolerancia bancaria da por pagada la cuota y deja el ajuste registrado', function () {
    $id = ($this->enviar)([$this->c1->id]);

    $r = ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar", ['monto_recibido' => '79.60'])->assertOk();

    expect($r->json('data.diferencia'))->toBe('-0.40')->and(($this->saldos)())->toBe(['80.00', '0.00', '0.00']);
    expect((array) enCondominio($this->condominio, fn () => DB::table('pago_aplicaciones')->where('pago_id', $id)->first()))->toMatchArray(['monto' => '79.60', 'ajuste' => '0.40'])
        ->and(($this->favor)())->toBe('0.00');
});

it('un monto que no alcanza para una cuota queda entero como saldo a favor', function () {
    $id = ($this->enviar)([$this->c1->id]);

    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar", ['monto_recibido' => '50.00'])->assertOk();

    expect(($this->saldos)())->toBe(['0.00', '0.00', '0.00'])->and(($this->favor)())->toBe('50.00');
});

it('rechaza con motivo: las cuotas quedan libres, avisa y el número se puede reusar', function () {
    $id = ($this->enviar)([$this->c1->id], '2002');

    ($this->admin)()->postJson("/api/v1/pagos/{$id}/rechazar", [])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/rechazar", ['motivo' => 'El monto no coincide con el banco'])->assertOk()->assertJsonPath('data.estado', 'rechazado');

    expect(($this->saldos)())->toBe(['0.00', '0.00', '0.00']);
    Mail::assertQueued(PagoRechazadoMail::class, fn ($m) => $m->motivo === 'El monto no coincide con el banco');
    $cuenta = collect((($this->residente)())->getJson('/api/v1/mi-cuenta')->json('data.unidades.0.pagos'))->first();
    expect($cuenta)->toMatchArray(['estado' => 'rechazado', 'motivo_rechazo' => 'El monto no coincide con el banco']);
    ($this->enviar)([$this->c1->id], '2002');
});

it('un pago ya revisado no se aprueba ni se rechaza otra vez', function () {
    $id = ($this->enviar)([$this->c1->id]);
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk();

    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertStatus(409)->assertJsonPath('error.code', 'PAGO_NO_PENDIENTE');
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/rechazar", ['motivo' => 'Tarde'])->assertStatus(409)->assertJsonPath('error.code', 'PAGO_NO_PENDIENTE');
    expect(($this->saldos)())->toBe(['80.00', '0.00', '0.00']);
});

it('los recibos son consecutivos y no se repiten', function () {
    $a = ($this->enviar)([$this->c1->id], '3001');
    $b = ($this->enviar)([$this->c2->id], '3002');

    $primero = ($this->admin)()->postJson("/api/v1/pagos/{$a}/aprobar")->json('data.recibo');
    $segundo = ($this->admin)()->postJson("/api/v1/pagos/{$b}/aprobar")->json('data.recibo');

    expect([$primero, $segundo])->toBe(['000001', '000002']);
});

it('la numeración es de cada condominio', function () {
    $otro = Condominio::factory()->create();
    $propio = enCondominio($this->condominio, fn () => [app(SequenceService::class)->siguiente('recibos'), app(SequenceService::class)->siguiente('recibos')]);
    $ajeno = enCondominio($otro, fn () => app(SequenceService::class)->siguiente('recibos'));
    $otraSerie = enCondominio($this->condominio, fn () => app(SequenceService::class)->siguiente('actas'));

    expect($propio)->toBe([1, 2])->and($ajeno)->toBe(1)->and($otraSerie)->toBe(1);
});

it('lista los pagos por estado con sus conteos y la tolerancia', function () {
    $a = ($this->enviar)([$this->c1->id], '4001');
    ($this->enviar)([$this->c2->id], '4002');
    ($this->admin)()->postJson("/api/v1/pagos/{$a}/aprobar")->assertOk();

    $pendientes = ($this->admin)()->getJson('/api/v1/pagos')->assertOk();
    $aprobados = ($this->admin)()->getJson('/api/v1/pagos?estado=aprobado')->assertOk();

    expect($pendientes->json('data.0.validacion'))->toBe(['nivel' => 'ok', 'texto' => 'Todo coincide'])
        ->and($aprobados->json('data.0.validacion'))->toBeNull()
        ->and($pendientes->json('data'))->toHaveCount(1)
        ->and($pendientes->json('data.0'))->toMatchArray(['unidad' => 'A-102', 'estado' => 'pendiente', 'monto' => '80.00', 'numero_comprobante' => '4002', 'banco' => 'Banco Pichincha'])
        ->and($pendientes->json('meta.conteos'))->toBe(['pendiente' => 1, 'aprobado' => 1, 'rechazado' => 0])
        ->and($pendientes->json('meta.tolerancia'))->toBe('0.50')
        ->and($aprobados->json('data.0.recibo'))->toBe('000001');
    ($this->admin)()->getJson('/api/v1/pagos?estado=otro')->assertStatus(422);
});

it('el detalle trae el comprobante, las validaciones y qué pasaría al aprobar', function () {
    $id = ($this->enviar)([$this->c1->id, $this->c2->id]);

    $d = ($this->admin)()->getJson("/api/v1/pagos/{$id}")->assertOk()->json('data');

    expect($d['unidad'])->toBe('A-102')
        ->and($d['comprobante_url'])->toBeString()->not->toBeEmpty()
        ->and($d['aplicacion'])->toBe(['cuotas_completas' => 2, 'ajuste' => '0.00', 'a_saldo_favor' => '0.00', 'credito_previo' => '0.00'])
        ->and(collect($d['validaciones'])->every(fn ($v) => $v['ok']))->toBeTrue()
        ->and($d['cuotas'])->toHaveCount(2);
});

it('avisa cuando el pago ya no cuadra con las cuotas', function () {
    $id = ($this->enviar)([$this->c1->id]);
    // Otra vía (efectivo) ya pagó esa cuota mientras tanto
    enCondominio($this->condominio, fn () => Cuota::query()->whereKey($this->c1->id)->update(['pagado' => '80.00']));

    $validaciones = collect(($this->admin)()->getJson("/api/v1/pagos/{$id}")->json('data.validaciones'))->keyBy('clave');

    expect($validaciones['cuotas_vigentes']['ok'])->toBeFalse();
});

it('aprobar en lote deja pendientes los que no pasan las validaciones', function () {
    $a = ($this->enviar)([$this->c1->id], '5001');
    $b = ($this->enviar)([$this->c2->id], '5002');
    enCondominio($this->condominio, fn () => Cuota::query()->whereKey($this->c2->id)->update(['pagado' => '80.00']));

    $r = ($this->admin)()->postJson('/api/v1/pagos/aprobar-lote', ['ids' => [$a, $b, 99999]])->assertOk();

    expect(collect($r->json('data'))->keyBy('id')->map(fn ($x) => $x['ok'] ? 'ok' : $x['codigo'])->all())
        ->toBe([$a => 'ok', $b => 'VALIDACION_FALLIDA', 99999 => 'PAGO_NO_ENCONTRADO'])
        ->and($r->json('meta'))->toBe(['aprobados' => 1, 'omitidos' => 2]);
    expect(enCondominio($this->condominio, fn () => Pago::query()->findOrFail($b)->estado))->toBe('pendiente');
    ($this->admin)()->postJson('/api/v1/pagos/aprobar-lote', ['ids' => []])->assertStatus(422);
});

it('el tesorero aprueba; el guardia, el contador y el residente no', function () {
    $id = ($this->enviar)([$this->c1->id]);

    [, $guardia] = sesionConRol($this->condominio, Rol::Guardia);
    [, $contador] = sesionConRol($this->condominio, Rol::Contador, true);
    foreach ([$guardia, $contador, $this->tokenResidente] as $token) {
        cambiarDeUsuario();
        $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/pagos')->assertForbidden();
        $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->postJson("/api/v1/pagos/{$id}/aprobar")->assertForbidden();
    }

    [, $tesorero] = sesionConRol($this->condominio, Rol::Tesorero);
    cambiarDeUsuario();
    $this->withToken($tesorero)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk();
});

it('otro condominio no ve ni aprueba mis pagos', function () {
    $id = ($this->enviar)([$this->c1->id]);
    $otro = Condominio::factory()->create();
    [, $token] = usuarioConToken($otro);
    cambiarDeUsuario();
    $ajeno = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id);

    expect($ajeno()->getJson('/api/v1/pagos')->json('data'))->toBe([]);
    $ajeno()->getJson("/api/v1/pagos/{$id}")->assertNotFound();
    $ajeno()->postJson("/api/v1/pagos/{$id}/aprobar")->assertNotFound();
});

it('el recibo en PDF solo existe para pagos aprobados y lo descargan la administración y el dueño', function () {
    $id = ($this->enviar)([$this->c1->id]);

    ($this->admin)()->get("/api/v1/pagos/{$id}/recibo")->assertStatus(409)->assertJsonPath('error.code', 'PAGO_SIN_RECIBO');
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk();

    $admin = ($this->admin)()->get("/api/v1/pagos/{$id}/recibo")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($admin->getContent(), 0, 5))->toBe('%PDF-')->and($admin->headers->get('Content-Disposition'))->toContain('recibo-000001.pdf');

    $propio = (($this->residente)())->get("/api/v1/mi-cuenta/pagos/{$id}/recibo")->assertOk();
    expect(substr($propio->getContent(), 0, 5))->toBe('%PDF-');
});

it('un residente no descarga el recibo de otra unidad', function () {
    $id = ($this->enviar)([$this->c1->id]);
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk();
    [, $tokenAjeno] = residenteDe($this->condominio, 'B-9');
    cambiarDeUsuario();

    $this->withToken($tokenAjeno)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->get("/api/v1/mi-cuenta/pagos/{$id}/recibo")->assertNotFound();
});

it('Mi cuenta muestra el número de recibo de los pagos aprobados', function () {
    $id = ($this->enviar)([$this->c1->id]);
    ($this->admin)()->postJson("/api/v1/pagos/{$id}/aprobar")->assertOk();

    $pago = collect((($this->residente)())->getJson('/api/v1/mi-cuenta')->json('data.unidades.0.pagos'))->first();

    expect($pago)->toMatchArray(['estado' => 'aprobado', 'recibo' => '000001']);
});
