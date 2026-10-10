<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create(['codigo' => 'JV', 'nombre' => 'Jardines del Valle']);
    [, $adminToken] = usuarioConToken($this->condominio);
    $this->admin = function () use ($adminToken) {
        cambiarDeUsuario();

        return $this->withToken($adminToken)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    enCondominio($this->condominio, fn () => ConfiguracionCobro::create(['metodo' => 'general', 'cuota_general' => '80.00', 'dia_vencimiento' => 10, 'aplica_desde' => hoyLocal()->subYear()->toDateString()]));

    $this->mes = hoyLocal()->startOfMonth();
    $this->unidad = fn (string $codigo, array $extra = []) => enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => $codigo, 'cuota_mensual' => '80.00'] + $extra));
    $this->a = ($this->unidad)('A-101', ['alicuota' => '1.0000']);
    $this->b = ($this->unidad)('B-102', ['alicuota' => '2.0000']);
    $this->c = ($this->unidad)('C-103', ['alicuota' => '3.0000']);
});

it('lista las cuotas del mes con el estado de cada unidad y los totales', function () {
    $pagada = cuotaDe($this->condominio, $this->a, 0, '80.00', '80.00');
    $pendiente = cuotaDe($this->condominio, $this->b, 0);
    cuotaDe($this->condominio, $this->c, 0);
    // C tiene un pago en revisión que declara este mes
    enCondominio($this->condominio, fn () => Pago::create([
        'unidad_id' => $this->c->id, 'monto' => '80.00', 'fecha_pago' => hoyLocal()->toDateString(), 'estado' => 'pendiente', 'numero_comprobante' => 'X1',
        'cuotas_declaradas' => [['cuota_id' => 1, 'periodo' => $this->mes->format('Y-m'), 'monto' => '80.00']],
    ]));
    // Deuda vieja de B: vencida de un mes anterior
    cuotaDe($this->condominio, $this->b, 2);

    $r = ($this->admin)()->getJson('/api/v1/finanzas/cuotas?periodo='.$this->mes->format('Y-m'))->assertOk();

    $porCodigo = collect($r->json('data'))->keyBy('codigo');
    expect($porCodigo['A-101']['estado'])->toBe('pagada')
        ->and($porCodigo['B-102']['estado'])->toBe('pendiente')
        ->and($porCodigo['C-103']['estado'])->toBe('por_aprobar')
        ->and($r->json('meta.conteos'))->toBe(['todas' => 3, 'pendiente' => 1, 'por_aprobar' => 1, 'vencida' => 0, 'pagada' => 1])
        ->and($r->json('meta.resumen.emitido'))->toBe(['monto' => '240.00', 'unidades' => 3])
        ->and($r->json('meta.resumen.cobrado'))->toBe(['monto' => '80.00', 'porcentaje' => 33.3])
        ->and($r->json('meta.resumen.por_aprobar'))->toBe(['monto' => '80.00', 'comprobantes' => 1])
        ->and($r->json('meta.resumen.vencido_anterior'))->toBe(['monto' => '80.00', 'unidades' => 1]);
});

it('filtra por estado y busca por unidad o responsable', function () {
    cuotaDe($this->condominio, $this->a, 0, '80.00', '80.00');
    cuotaDe($this->condominio, $this->b, 0);
    enCondominio($this->condominio, function () {
        $p = Persona::factory()->create(['nombres' => 'Carmen', 'apellidos' => 'Loor']);
        Ocupante::create(['unidad_id' => $this->b->id, 'persona_id' => $p->id, 'relacion' => 'propietario', 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);
    });
    $periodo = $this->mes->format('Y-m');

    $pagadas = ($this->admin)()->getJson("/api/v1/finanzas/cuotas?periodo={$periodo}&filtro=pagada")->json('data');
    $porNombre = ($this->admin)()->getJson("/api/v1/finanzas/cuotas?periodo={$periodo}&buscar=loor")->json('data');
    $porCodigo = ($this->admin)()->getJson("/api/v1/finanzas/cuotas?periodo={$periodo}&buscar=a-10")->json('data');

    expect(collect($pagadas)->pluck('codigo')->all())->toBe(['A-101'])
        ->and(collect($porNombre)->pluck('codigo')->all())->toBe(['B-102'])
        ->and($porNombre[0]['responsable'])->toBe(['nombre' => 'Carmen Loor', 'relacion' => 'propietario'])
        ->and(collect($porCodigo)->pluck('codigo')->all())->toBe(['A-101']);
    ($this->admin)()->getJson('/api/v1/finanzas/cuotas?filtro=otro')->assertStatus(422);
    ($this->admin)()->getJson('/api/v1/finanzas/cuotas?periodo=hoy')->assertStatus(422);
});

it('una cuota extraordinaria igual para todas las unidades que pagan cuota', function () {
    $r = ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', [
        'detalle' => 'Pintura de fachada', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDays(20)->toDateString(), 'modo' => 'por_unidad', 'monto' => '45.00',
    ])->assertCreated();

    expect($r->json('data'))->toMatchArray(['creadas' => 3, 'total' => '135.00'])
        ->and(enCondominio($this->condominio, fn () => Cuota::query()->where('concepto', 'extraordinaria')->pluck('monto')->map(fn ($m) => (string) $m)->all()))->toBe(['45.00', '45.00', '45.00']);
});

it('repartida por alícuota suma exactamente el total, sin centavos perdidos', function () {
    $r = ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', [
        'detalle' => 'Cisterna', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDays(20)->toDateString(), 'modo' => 'alicuota', 'monto' => '100.00',
    ])->assertCreated();

    $montos = enCondominio($this->condominio, fn () => Cuota::query()->where('concepto', 'extraordinaria')->orderBy('unidad_id')->pluck('monto')->map(fn ($m) => (string) $m)->all());
    expect($r->json('data.total'))->toBe('100.00')->and($montos)->toBe(['16.67', '33.33', '50.00']);
});

it('por alícuota exige que todas las unidades la tengan', function () {
    ($this->unidad)('D-104', ['alicuota' => null]);

    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', [
        'detalle' => 'Cisterna', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDays(5)->toDateString(), 'modo' => 'alicuota', 'monto' => '100.00',
    ])->assertStatus(422)->assertJsonPath('error.code', 'UNIDADES_SIN_ALICUOTA');
    expect(enCondominio($this->condominio, fn () => Cuota::query()->count()))->toBe(0);
});

it('la extraordinaria no se repite con el mismo detalle, ni en mes cerrado, ni con vencimiento pasado', function () {
    $datos = ['detalle' => 'Cisterna', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDays(5)->toDateString(), 'modo' => 'por_unidad', 'monto' => '10.00'];
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', $datos)->assertCreated();

    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'cisterna'] + $datos)->assertStatus(409)->assertJsonPath('error.code', 'EXTRAORDINARIA_EXISTENTE');
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'Otra', 'vence_el' => hoyLocal()->subDay()->toDateString()] + $datos)->assertStatus(422)->assertJsonPath('error.code', 'VENCIMIENTO_PASADO');
    // Pero otra con otro detalle en el mismo mes sí
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'Portón'] + $datos)->assertCreated();

    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => $this->mes->toDateString(), 'estado' => 'cerrado']));
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'Nueva'] + $datos)->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
});

it('valida la cuota extraordinaria y solo quien emite cuotas la crea', function () {
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', [])->assertStatus(422);
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'Pintura', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDay()->toDateString(), 'modo' => 'por_unidad', 'monto' => '0'])->assertStatus(422);

    [, $token] = usuarioConToken($this->condominio, Rol::Tesorero);
    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->postJson('/api/v1/finanzas/cuotas-extraordinarias', ['detalle' => 'Pintura', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDay()->toDateString(), 'modo' => 'por_unidad', 'monto' => '10'])
        ->assertForbidden();
});

it('el pago en efectivo se aprueba en el acto, sale su recibo y baja la deuda', function () {
    $c1 = cuotaDe($this->condominio, $this->a, 2);
    cuotaDe($this->condominio, $this->a, 1);
    enCondominio($this->condominio, function () {
        $p = Persona::factory()->create(['nombres' => 'Diego', 'apellidos' => 'Mora']);
        Ocupante::create(['unidad_id' => $this->a->id, 'persona_id' => $p->id, 'relacion' => 'propietario', 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);
    });

    $r = ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '80.00'])->assertCreated();

    expect($r->json('data'))->toMatchArray(['monto' => '80.00', 'recibo' => '000001']);
    $pago = enCondominio($this->condominio, fn () => Pago::query()->firstOrFail());
    expect($pago->metodo)->toBe('efectivo')->and($pago->estado)->toBe('aprobado')->and($pago->user_id)->toBeNull()->and($pago->pagador_nombre)->toBe('Diego Mora')
        ->and(enCondominio($this->condominio, fn () => (string) Cuota::query()->findOrFail($c1->id)->pagado))->toBe('80.00');
    Mail::assertNothingQueued();

    ($this->admin)()->get('/api/v1/pagos/'.$pago->id.'/recibo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('valida el pago en efectivo: monto, fecha y unidad del condominio', function () {
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '0'])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '10.123'])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '10', 'fecha' => hoyLocal()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_FUTURA');
    ($this->admin)()->postJson('/api/v1/unidades/999999/pagos-efectivo', ['monto' => '10'])->assertStatus(404);

    $ajena = enCondominio($ajeno = Condominio::factory()->create(), fn () => Unidad::factory()->create());
    ($this->admin)()->postJson("/api/v1/unidades/{$ajena->id}/pagos-efectivo", ['monto' => '10'])->assertStatus(404);
    expect(enCondominio($this->condominio, fn () => Pago::query()->count()))->toBe(0);
});

it('solo quien aprueba pagos registra efectivo', function () {
    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $this->withToken($guardia)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '10'])->assertForbidden();

    [, $tesorero] = usuarioConToken($this->condominio, Rol::Tesorero);
    cambiarDeUsuario();
    $this->withToken($tesorero)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '10'])->assertCreated();
});

it('el estado de cuenta muestra cargos, abonos y el saldo corriente; negativo es a favor', function () {
    cuotaDe($this->condominio, $this->a, 2);
    cuotaDe($this->condominio, $this->a, 1);
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '100.00'])->assertCreated();
    cuotaDe($this->condominio, $this->a, 0);

    $r = ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta")->assertOk()->json('data');

    $mov = collect($r['movimientos']);
    expect($r['unidad']['codigo'])->toBe('A-101')
        // Las cuotas se cobran el día 1 de su mes; el pago es de hoy: va al final
        ->and($mov->pluck('saldo')->all())->toBe(['80.00', '160.00', '240.00', '140.00'])
        ->and($mov->pluck('cargo')->all())->toBe(['80.00', '80.00', '80.00', null])
        ->and($mov->pluck('abono')->all())->toBe([null, null, null, '100.00'])
        ->and($mov[3]['concepto'])->toContain('Pago en efectivo')
        // 100 pagó una cuota de 80 y dejó 20 a favor; deben 2 cuotas (160) menos ese saldo
        ->and($r['saldo_favor'])->toBe('20.00')
        ->and($r['saldo_pendiente'])->toBe('140.00')
        ->and($r['pagado_en_el_anio'])->toMatchArray(['monto' => '100.00', 'pagos' => 1]);
});

it('el saldo a favor sale en el estado de cuenta y se descuenta de lo pendiente', function () {
    cuotaDe($this->condominio, $this->a, 1);
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '93.00'])->assertCreated();
    cuotaDe($this->condominio, $this->a, 0);

    $r = ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta")->json('data');

    // 80 pagada con 93: 13 a favor; la cuota nueva de 80 queda en 67
    expect($r['saldo_favor'])->toBe('13.00')->and($r['saldo_pendiente'])->toBe('67.00');
});

it('la tolerancia bancaria aparece como ajuste y deja el saldo en cero', function () {
    cuotaDe($this->condominio, $this->a, 0);
    ($this->admin)()->postJson("/api/v1/unidades/{$this->a->id}/pagos-efectivo", ['monto' => '79.60'])->assertCreated();

    $r = ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta")->json('data');

    expect(collect($r['movimientos'])->pluck('concepto')->last())->toBe('Ajuste por tolerancia bancaria')
        ->and(collect($r['movimientos'])->pluck('saldo')->last())->toBe('0.00')
        ->and($r['saldo_pendiente'])->toBe('0.00');
});

it('los días de atraso cuentan desde la cuota vencida más antigua', function () {
    cuotaDe($this->condominio, $this->a, 3);

    $r = ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta")->json('data');
    $vence = hoyLocal()->startOfMonth()->subMonths(3)->day(10);

    expect($r['dias_atraso'])->toBe((int) $vence->diffInDays(hoyLocal()));
});

it('el rango recorta los movimientos y calcula el saldo inicial', function () {
    cuotaDe($this->condominio, $this->a, 2);
    cuotaDe($this->condominio, $this->a, 1);
    cuotaDe($this->condominio, $this->a, 0);
    $desde = hoyLocal()->startOfMonth()->subMonth()->toDateString();

    $r = ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta?desde={$desde}")->json('data');

    expect($r['saldo_inicial'])->toBe('80.00')->and(collect($r['movimientos'])->pluck('saldo')->all())->toBe(['160.00', '240.00']);
    ($this->admin)()->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta?desde=2026-10-10&hasta=2026-01-01")->assertStatus(422);
});

it('el estado de cuenta en PDF, la unidad ajena y los permisos', function () {
    cuotaDe($this->condominio, $this->a, 0);

    $pdf = ($this->admin)()->get("/api/v1/unidades/{$this->a->id}/estado-cuenta/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 5))->toBe('%PDF-')->and($pdf->headers->get('Content-Disposition'))->toContain('estado-de-cuenta-A101.pdf');

    ($this->admin)()->getJson('/api/v1/unidades/999999/estado-cuenta')->assertNotFound();
    $ajena = enCondominio(Condominio::factory()->create(), fn () => Unidad::factory()->create());
    ($this->admin)()->getJson("/api/v1/unidades/{$ajena->id}/estado-cuenta")->assertNotFound();

    [, $guardia] = usuarioConToken($this->condominio, Rol::Guardia);
    cambiarDeUsuario();
    $this->withToken($guardia)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson("/api/v1/unidades/{$this->a->id}/estado-cuenta")->assertForbidden();
});

it('Mi cuenta nombra la cuota extraordinaria', function () {
    [, $token, $unidad] = residenteDe($this->condominio, 'Z-1');
    ($this->admin)()->postJson('/api/v1/finanzas/cuotas-extraordinarias', [
        'detalle' => 'Pintura de fachada', 'periodo' => $this->mes->format('Y-m'), 'vence_el' => hoyLocal()->addDays(10)->toDateString(), 'modo' => 'por_unidad', 'monto' => '45.00',
    ])->assertCreated();
    cambiarDeUsuario();

    $cuota = $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/mi-cuenta')->json('data.unidades.0.cuotas.0');

    expect($cuota)->toMatchArray(['concepto' => 'extraordinaria', 'detalle' => 'Pintura de fachada', 'monto' => '45.00']);
});
