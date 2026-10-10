<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['total_unidades' => 50]);
    [$this->admin, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $this->mes = enCondominio($this->condominio, fn () => CarbonImmutable::parse(app(Calendario::class)->hoy())->startOfMonth());
    $this->periodo = $this->mes->format('Y-m');

    $this->configurar = fn (int $dia = 10, ?CarbonImmutable $desde = null) => enCondominio($this->condominio, fn () => ConfiguracionCobro::create([
        'metodo' => 'general', 'cuota_general' => '80.00', 'dia_vencimiento' => $dia, 'aplica_desde' => ($desde ?? $this->mes->subMonths(2))->toDateString(),
    ]));
    $this->unidad = fn (string $cuota = '80.00', ?string $codigo = null) => enCondominio($this->condominio, fn () => Unidad::factory()->create(
        ['cuota_mensual' => $cuota] + ($codigo ? ['codigo' => $codigo] : []),
    ));
    $this->cuotas = fn () => enCondominio($this->condominio, fn () => Cuota::query()->orderBy('unidad_id')->get());
});

it('emite una cuota por unidad con el valor que ya tiene y la fecha de vencimiento', function () {
    ($this->configurar)(10);
    ($this->unidad)('80.00');
    ($this->unidad)('120.50');
    ($this->unidad)('0.00'); // sin cuota: no se cobra

    $r = ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();

    expect($r->json('data'))->toMatchArray([
        'periodo' => $this->periodo, 'creadas' => 2, 'existentes' => 0, 'sin_cuota' => 1, 'total' => '200.50', 'vence_el' => $this->mes->day(10)->toDateString(),
    ]);
    $cuotas = ($this->cuotas)();
    expect($cuotas->pluck('monto')->all())->toBe(['80.00', '120.50'])
        ->and($cuotas->pluck('pagado')->unique()->all())->toBe(['0.00'])
        ->and(enCondominio($this->condominio, fn () => PeriodoFinanciero::query()->first()->estado))->toBe('abierto');
});

it('con vencimiento 0 la cuota vence el último día del mes', function () {
    ($this->configurar)(0);
    ($this->unidad)();

    $r = ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();

    expect($r->json('data.vence_el'))->toBe($this->mes->endOfMonth()->toDateString());
});

it('emitir otra vez no duplica: solo agrega las unidades nuevas y no cambia lo emitido', function () {
    ($this->configurar)();
    ($this->unidad)('80.00');
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();

    ($this->unidad)('95.00');
    enCondominio($this->condominio, fn () => Unidad::query()->orderBy('id')->first()->update(['cuota_mensual' => '999.00']));
    $r = ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();

    expect($r->json('data'))->toMatchArray(['creadas' => 1, 'existentes' => 1, 'total' => '175.00'])
        ->and(($this->cuotas)()->pluck('monto')->all())->toBe(['80.00', '95.00'])
        ->and(enCondominio($this->condominio, fn () => PeriodoFinanciero::query()->count()))->toBe(1);
});

it('no emite sin cobro configurado, antes de que aplique, un mes futuro ni sin unidades a cobrar', function () {
    ($this->unidad)();
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])
        ->assertStatus(409)->assertJsonPath('error.code', 'COBRO_SIN_CONFIGURAR');

    ($this->configurar)(10, $this->mes);
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->mes->subMonth()->format('Y-m')])
        ->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_ANTERIOR_AL_COBRO');
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->mes->addMonth()->format('Y-m')])
        ->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_FUTURO');

    enCondominio($this->condominio, fn () => Unidad::query()->update(['cuota_mensual' => null]));
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])
        ->assertStatus(409)->assertJsonPath('error.code', 'SIN_UNIDADES_A_COBRAR');

    expect(($this->cuotas)())->toHaveCount(0);
});

it('un mes cerrado no se vuelve a emitir y el formato se valida', function () {
    ($this->configurar)();
    ($this->unidad)();
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => $this->mes->toDateString(), 'estado' => 'cerrado']));

    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])
        ->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => '2026-13'])->assertStatus(422);
    ($this->api)()->postJson('/api/v1/finanzas/periodos', [])->assertStatus(422);
});

it('lista los meses emitidos con lo esperado y lo recaudado', function () {
    ($this->configurar)();
    $u = ($this->unidad)('80.00');
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();
    enCondominio($this->condominio, fn () => Cuota::query()->where('unidad_id', $u->id)->update(['pagado' => '30.00']));

    $lista = ($this->api)()->getJson('/api/v1/finanzas/periodos')->assertOk()->json('data');

    expect($lista)->toBe([['periodo' => $this->periodo, 'estado' => 'abierto', 'esperado' => '80.00', 'recaudado' => '30.00']]);
});

it('el resumen separa lo del mes de la cartera vencida y su antigüedad', function () {
    ($this->configurar)();
    $hoy = enCondominio($this->condominio, fn () => CarbonImmutable::parse(app(Calendario::class)->hoy()));
    [$a, $b, $c, $d] = [($this->unidad)('80.00'), ($this->unidad)('80.00'), ($this->unidad)('80.00'), ($this->unidad)('80.00')];

    $cuota = fn ($u, CarbonImmutable $periodo, string $monto, string $pagado, CarbonImmutable $vence) => enCondominio($this->condominio, fn () => Cuota::create([
        'unidad_id' => $u->id, 'periodo' => $periodo->toDateString(), 'concepto' => 'ordinaria', 'monto' => $monto, 'pagado' => $pagado, 'vence_el' => $vence->toDateString(),
    ]));
    $cuota($a, $this->mes, '80.00', '80.00', $hoy->addDays(5));            // pagada
    $cuota($b, $this->mes, '80.00', '30.00', $hoy->addDays(5));            // al día, saldo 50
    $cuota($c, $this->mes->subMonth(), '80.00', '0.00', $hoy->subDays(15)); // 1-30 días
    $cuota($d, $this->mes->subMonths(4), '80.00', '20.00', $hoy->subDays(100)); // más de 90, saldo 60
    $cuota($d, $this->mes->subMonths(2), '80.00', '0.00', $hoy->subDays(45));   // 31-60 (misma unidad)

    $r = ($this->api)()->getJson("/api/v1/finanzas/resumen?periodo={$this->periodo}")->assertOk()->json('data');
    $tramos = collect($r['antiguedad'])->keyBy('tramo');

    expect($r)->toMatchArray(['periodo' => $this->periodo, 'emitido' => false, 'esperado' => '160.00', 'recaudado' => '110.00', 'cuotas' => 2])
        ->and($r['cartera_vencida'])->toBe(['saldo' => '220.00', 'unidades' => 2])
        ->and($tramos['al_dia']['saldo'])->toBe('50.00')
        ->and($tramos['al_dia']['unidades'])->toBe(1)
        ->and($tramos['d1_30'])->toMatchArray(['saldo' => '80.00', 'unidades' => 1])
        ->and($tramos['d31_60'])->toMatchArray(['saldo' => '80.00', 'unidades' => 1])
        ->and($tramos['d61_90']['saldo'])->toBe('0.00')
        ->and($tramos['d90_mas'])->toMatchArray(['saldo' => '60.00', 'unidades' => 1])
        ->and($r['cobro'])->toBe(['metodo' => 'general', 'dia_vencimiento' => 10]);
});

it('sin periodo el resumen es del mes en curso y un mes sin emitir sale en cero', function () {
    ($this->configurar)();

    $r = ($this->api)()->getJson('/api/v1/finanzas/resumen')->assertOk()->json('data');

    expect($r)->toMatchArray(['periodo' => $this->periodo, 'emitido' => false, 'esperado' => '0.00', 'recaudado' => '0.00']);
    ($this->api)()->getJson('/api/v1/finanzas/resumen?periodo=hoy')->assertStatus(422);
});

it('otro condominio no ve las cuotas', function () {
    ($this->configurar)();
    ($this->unidad)();
    ($this->api)()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertCreated();

    $otro = Condominio::factory()->create();
    [, $token] = usuarioConToken($otro);
    cambiarDeUsuario();
    $r = $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson("/api/v1/finanzas/resumen?periodo={$this->periodo}")->assertOk();

    expect($r->json('data.esperado'))->toBe('0.00')
        ->and($this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/finanzas/periodos')->json('data'))->toBe([]);
});

it('tesorero y contador ven las finanzas pero no emiten; el guardia no entra', function () {
    ($this->configurar)();
    ($this->unidad)();

    foreach ([Rol::Tesorero, Rol::Contador] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $con = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
        $con()->getJson('/api/v1/finanzas/resumen')->assertOk();
        $con()->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertForbidden();
    }

    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/finanzas/resumen')->assertForbidden();
});
