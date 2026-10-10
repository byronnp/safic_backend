<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Un residente con cuenta, dueño de una unidad. Devuelve [user, token, unidad]. */
function residenteDe(Condominio $condominio, string $codigo, string $relacion = 'propietario', array $unidad = []): array
{
    return enCondominio($condominio, function () use ($condominio, $codigo, $relacion, $unidad) {
        $u = Unidad::factory()->create(['codigo' => $codigo, 'cuota_mensual' => '80.00'] + $unidad);
        $user = User::factory()->miembroDe($condominio, Rol::Residente)->create();
        $p = Persona::factory()->create();
        $p->forceFill(['user_id' => $user->id])->save();
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => $p->id, 'relacion' => $relacion, 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);

        return [$user, auth('api')->tokenById($user->id), $u];
    });
}

function cuotaDe(Condominio $condominio, Unidad $unidad, int $mesesAtras, string $monto = '80.00', string $pagado = '0.00'): Cuota
{
    return enCondominio($condominio, function () use ($unidad, $mesesAtras, $monto, $pagado) {
        $periodo = hoyLocal()->startOfMonth()->subMonths($mesesAtras);

        return Cuota::create([
            'unidad_id' => $unidad->id, 'periodo' => $periodo->toDateString(), 'concepto' => 'ordinaria', 'monto' => $monto,
            'pagado' => $pagado, 'vence_el' => $periodo->day(10)->toDateString(),
        ]);
    });
}

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);

    $this->condominio = Condominio::factory()->create(['codigo' => 'JV', 'nombre' => 'Jardines del Valle']);
    [, $adminToken] = usuarioConToken($this->condominio);
    $this->admin = fn () => $this->withToken($adminToken)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    enCondominio($this->condominio, fn () => CuentaBancaria::create(['banco' => 'Banco Pichincha', 'tipo' => 'corriente', 'numero' => '2100123456', 'titular' => 'Condominio Jardines del Valle', 'es_principal' => true]));

    [$this->user, $token, $this->unidad] = residenteDe($this->condominio, 'A-102');
    $this->residente = function () use ($token) {
        cambiarDeUsuario();

        return $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->comprobante = fn (string $nombre = 'comprobante.jpg') => UploadedFile::fake()->image($nombre, 600, 800);
    $this->pagar = fn (array $cuotas, array $extra = []) => ($this->residente)()->post('/api/v1/mi-cuenta/pagos', $extra + [
        'unidad_id' => $this->unidad->id, 'cuotas' => $cuotas, 'numero_comprobante' => '004518823', 'comprobante' => ($this->comprobante)(),
    ], ['Accept' => 'application/json']);
});

it('muestra solo mis unidades con lo que debo, lo vencido y a dónde transferir', function () {
    $vieja = cuotaDe($this->condominio, $this->unidad, 2);
    $actual = cuotaDe($this->condominio, $this->unidad, 0);
    cuotaDe($this->condominio, $this->unidad, 1, '80.00', '80.00'); // pagada: no aparece
    [, , $ajena] = residenteDe($this->condominio, 'B-9');
    cuotaDe($this->condominio, $ajena, 0);

    $r = ($this->residente)()->getJson('/api/v1/mi-cuenta')->assertOk();
    $u = $r->json('data.unidades.0');

    expect($r->json('data.unidades'))->toHaveCount(1)
        ->and($u)->toMatchArray(['codigo' => 'A-102', 'encabezado' => 'Jardines del Valle · A-102', 'concepto_transferencia' => 'JV-A102', 'puede_pagar' => true, 'total_pendiente' => '160.00', 'vencidas' => 1, 'saldo_favor' => '0.00'])
        ->and(collect($u['cuotas'])->pluck('id')->all())->toBe([$vieja->id, $actual->id])
        ->and(collect($u['cuotas'])->pluck('estado')->all())->toBe(['vencida', 'pendiente'])
        ->and($r->json('data.cuenta_bancaria'))->toMatchArray(['banco' => 'Banco Pichincha', 'numero' => '2100123456']);
});

it('alguien sin ficha ligada a su cuenta no ve unidades', function () {
    $otro = User::factory()->miembroDe($this->condominio, Rol::Residente)->create();
    cambiarDeUsuario();

    $this->withToken(auth('api')->tokenById($otro->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->getJson('/api/v1/mi-cuenta')->assertOk()->assertJsonPath('data.unidades', []);
});

it('envía el pago: queda pendiente con el monto de las cuotas y el comprobante en el bucket', function () {
    $c1 = cuotaDe($this->condominio, $this->unidad, 2);
    $c2 = cuotaDe($this->condominio, $this->unidad, 1);
    cuotaDe($this->condominio, $this->unidad, 0);

    $r = ($this->pagar)([$c1->id, $c2->id])->assertCreated()->assertJsonPath('data.monto', '160.00')->assertJsonPath('data.estado', 'pendiente');

    $pago = enCondominio($this->condominio, fn () => Pago::query()->firstOrFail());
    expect($pago->unidad_id)->toBe($this->unidad->id)
        ->and($pago->numero_comprobante)->toBe('004518823')
        ->and($pago->cuotas_declaradas)->toHaveCount(2)
        ->and($pago->comprobante_ruta)->toStartWith("condominios/{$this->condominio->id}/comprobantes/");
    Storage::disk('local')->assertExists($pago->comprobante_ruta);

    // Las cuotas quedan «en revisión» y aún no cambian de saldo
    $cuotas = collect($r->json('data.cuenta.unidades.0.cuotas'));
    expect($cuotas->pluck('estado')->all())->toBe(['en_revision', 'en_revision', 'pendiente'])
        ->and(enCondominio($this->condominio, fn () => Cuota::query()->sum('pagado')))->toEqual(0);
});

it('se paga desde la cuota más antigua y completas: no se salta ninguna', function () {
    $c1 = cuotaDe($this->condominio, $this->unidad, 2);
    $c2 = cuotaDe($this->condominio, $this->unidad, 1);

    ($this->pagar)([$c2->id])->assertStatus(422)->assertJsonPath('error.code', 'CUOTAS_NO_VALIDAS');
    ($this->pagar)([$c1->id, 99999])->assertStatus(422)->assertJsonPath('error.code', 'CUOTAS_NO_VALIDAS');
    expect(enCondominio($this->condominio, fn () => Pago::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('una cuota en un pago en revisión no se puede elegir otra vez', function () {
    $c1 = cuotaDe($this->condominio, $this->unidad, 1);
    $c2 = cuotaDe($this->condominio, $this->unidad, 0);
    ($this->pagar)([$c1->id])->assertCreated();

    ($this->pagar)([$c1->id], ['numero_comprobante' => '999'])->assertStatus(409)->assertJsonPath('error.code', 'CUOTAS_EN_REVISION');
    // La siguiente libre sí
    ($this->pagar)([$c2->id], ['numero_comprobante' => '1000'])->assertCreated();
});

it('un mismo número de comprobante no se registra dos veces y no deja archivos sueltos', function () {
    $c1 = cuotaDe($this->condominio, $this->unidad, 1);
    $c2 = cuotaDe($this->condominio, $this->unidad, 0);
    ($this->pagar)([$c1->id])->assertCreated();

    ($this->pagar)([$c2->id])->assertStatus(409)->assertJsonPath('error.code', 'COMPROBANTE_DUPLICADO');
    ($this->pagar)([$c2->id], ['numero_comprobante' => '004518823'])->assertStatus(409);

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    // Si lo rechazaron, el número se puede volver a usar
    enCondominio($this->condominio, fn () => Pago::query()->update(['estado' => 'rechazado']));
    ($this->pagar)([$c1->id])->assertCreated();
});

it('el saldo a favor se descuenta del monto a transferir', function () {
    $pagada = cuotaDe($this->condominio, $this->unidad, 3, '80.00', '80.00');
    $cuota = cuotaDe($this->condominio, $this->unidad, 0);
    // Pagó 100 por una cuota de 80: le quedan 20 a favor
    enCondominio($this->condominio, function () use ($pagada) {
        $pago = Pago::create(['unidad_id' => $this->unidad->id, 'monto' => '100.00', 'fecha_pago' => hoyLocal()->toDateString(), 'estado' => 'aprobado', 'metodo' => 'efectivo']);
        DB::table('pago_aplicaciones')->insert(['condominio_id' => $this->condominio->id, 'pago_id' => $pago->id, 'cuota_id' => $pagada->id, 'monto' => '80.00', 'ajuste' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
    });

    expect(($this->residente)()->getJson('/api/v1/mi-cuenta')->json('data.unidades.0.saldo_favor'))->toBe('20.00');
    ($this->pagar)([$cuota->id])->assertCreated()->assertJsonPath('data.monto', '60.00');
});

it('si el saldo a favor cubre las cuotas no hace falta transferir', function () {
    $pagada = cuotaDe($this->condominio, $this->unidad, 3, '80.00', '80.00');
    $cuota = cuotaDe($this->condominio, $this->unidad, 0);
    enCondominio($this->condominio, function () use ($pagada) {
        $pago = Pago::create(['unidad_id' => $this->unidad->id, 'monto' => '160.00', 'fecha_pago' => hoyLocal()->toDateString(), 'estado' => 'aprobado', 'metodo' => 'efectivo']);
        DB::table('pago_aplicaciones')->insert(['condominio_id' => $this->condominio->id, 'pago_id' => $pago->id, 'cuota_id' => $pagada->id, 'monto' => '80.00', 'ajuste' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
    });

    ($this->pagar)([$cuota->id])->assertStatus(409)->assertJsonPath('error.code', 'NADA_QUE_TRANSFERIR');
});

it('exige la cuenta bancaria configurada y un comprobante válido', function () {
    $c = cuotaDe($this->condominio, $this->unidad, 0);

    ($this->pagar)([$c->id], ['comprobante' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422)->assertJsonValidationErrors('comprobante', 'error.fields');
    ($this->pagar)([$c->id], ['comprobante' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')])->assertStatus(422);
    ($this->pagar)([$c->id], ['numero_comprobante' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('numero_comprobante', 'error.fields');
    ($this->pagar)([])->assertStatus(422);

    enCondominio($this->condominio, fn () => CuentaBancaria::query()->update(['activa' => false]));
    ($this->pagar)([$c->id])->assertStatus(409)->assertJsonPath('error.code', 'SIN_CUENTA_BANCARIA');
});

it('solo el responsable de pago paga; nadie paga una unidad que no es suya', function () {
    // Unidad cuyo responsable de pago es el inquilino: el propietario la ve pero no paga
    [, $token, $unidad] = residenteDe($this->condominio, 'C-3', 'propietario', ['responsable_pago' => 'inquilino']);
    $cuota = cuotaDe($this->condominio, $unidad, 0);
    cambiarDeUsuario();
    $propietario = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $propietario()->getJson('/api/v1/mi-cuenta')->assertOk()->assertJsonPath('data.unidades.0.puede_pagar', false);
    $propietario()->post('/api/v1/mi-cuenta/pagos', ['unidad_id' => $unidad->id, 'cuotas' => [$cuota->id], 'numero_comprobante' => '555', 'comprobante' => ($this->comprobante)()], ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('error.code', 'NO_PUEDES_PAGAR_ESTA_UNIDAD');

    // Mi sesión no paga la unidad de otro
    ($this->residente)()->post('/api/v1/mi-cuenta/pagos', ['unidad_id' => $unidad->id, 'cuotas' => [$cuota->id], 'numero_comprobante' => '556', 'comprobante' => ($this->comprobante)()], ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('error.code', 'UNIDAD_NO_ES_TUYA');
});

it('otro condominio no ve mis cuotas ni sus unidades', function () {
    cuotaDe($this->condominio, $this->unidad, 0);
    $otro = Condominio::factory()->create();
    [, $token] = residenteDe($otro, 'X-1');
    cambiarDeUsuario();

    $r = $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/mi-cuenta')->assertOk();

    expect(collect($r->json('data.unidades'))->pluck('codigo')->all())->toBe(['X-1'])
        ->and($r->json('data.unidades.0.cuotas'))->toBe([]);
});

it('el guardia no entra a Mi cuenta', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/mi-cuenta')->assertForbidden();
});

it('la administración agrega cuentas bancarias: la primera es la principal y el número va cifrado', function () {
    enCondominio($this->condominio, fn () => CuentaBancaria::query()->delete());

    $a = ($this->admin)()->postJson('/api/v1/cuentas-bancarias', ['banco' => 'Produbanco', 'tipo' => 'ahorros', 'numero' => '7730-123', 'titular' => 'Condominio JV'])->assertCreated();
    $b = ($this->admin)()->postJson('/api/v1/cuentas-bancarias', ['banco' => 'Pichincha', 'tipo' => 'corriente', 'numero' => '2100999888', 'titular' => 'Condominio JV'])->assertCreated();

    expect($a->json('data.es_principal'))->toBeTrue()->and($b->json('data.es_principal'))->toBeFalse()
        ->and(DB::table('cuentas_bancarias')->where('id', $a->json('data.id'))->value('numero'))->not->toBe('7730-123');

    // Marcar la segunda como principal desmarca la primera
    ($this->admin)()->patchJson('/api/v1/cuentas-bancarias/'.$b->json('data.id'), ['es_principal' => true])->assertOk()->assertJsonPath('data.es_principal', true);
    $lista = collect(($this->admin)()->getJson('/api/v1/cuentas-bancarias')->json('data'));
    expect($lista->where('es_principal', true))->toHaveCount(1)->and($lista->firstWhere('es_principal', true)['id'])->toBe($b->json('data.id'));
});

it('desactivar la principal pasa el lugar a otra activa; no se quita sin dejar otra', function () {
    $nueva = ($this->admin)()->postJson('/api/v1/cuentas-bancarias', ['banco' => 'Produbanco', 'tipo' => 'ahorros', 'numero' => '7730-123', 'titular' => 'Condominio JV'])->json('data.id');
    $principal = enCondominio($this->condominio, fn () => CuentaBancaria::query()->where('es_principal', true)->value('id'));

    ($this->admin)()->patchJson("/api/v1/cuentas-bancarias/{$principal}", ['es_principal' => false])->assertStatus(422)->assertJsonPath('error.code', 'CUENTA_PRINCIPAL');
    ($this->admin)()->patchJson("/api/v1/cuentas-bancarias/{$principal}", ['activa' => false])->assertOk();

    $lista = collect(($this->admin)()->getJson('/api/v1/cuentas-bancarias')->json('data'));
    expect($lista->firstWhere('id', $nueva)['es_principal'])->toBeTrue()->and($lista->firstWhere('id', $principal)['es_principal'])->toBeFalse();
});

it('valida la cuenta bancaria y solo quien edita el condominio la administra', function () {
    ($this->admin)()->postJson('/api/v1/cuentas-bancarias', ['banco' => '', 'tipo' => 'otra', 'numero' => '12', 'titular' => ''])->assertStatus(422);
    ($this->admin)()->postJson('/api/v1/cuentas-bancarias', ['banco' => 'X', 'tipo' => 'ahorros', 'numero' => '12345', 'titular' => 'Condominio'])->assertStatus(422);

    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/cuentas-bancarias')->assertForbidden();
});
