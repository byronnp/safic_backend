<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);

    $this->condominio = Condominio::factory()->create();
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'presidente' => Rol::Presidente, 'vice' => Rol::Vicepresidente, 'contador' => Rol::Contador] as $clave => $rol) {
        [$u] = usuarioConToken($this->condominio, $rol);
        if ($rol === Rol::Contador) {
            $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        }
        $this->usuarios[$clave] = $u;
    }
    $this->como = function (string $clave) {
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($this->usuarios[$clave]->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };

    enCondominio($this->condominio, function () {
        $this->origen = CuentaBancaria::create(['banco' => 'Banco Pichincha', 'tipo' => 'corriente', 'numero' => '2100123456', 'titular' => 'Condominio', 'es_principal' => true]);
        $this->proveedor = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Ascensores Andinos S.A.']);
        ProveedorCuenta::create(['proveedor_id' => $this->proveedor->id, 'banco' => 'Produbanco', 'tipo' => 'corriente', 'numero' => '02005-1234-87', 'titular' => 'Ascensores Andinos S.A.', 'estado' => 'activa', 'activa_en' => now()]);
    });

    // Factura registrada por el tesorero
    $this->factura = fn (string $total = '350.00', array $extra = []) => enCondominio($this->condominio, fn () => Gasto::create($extra + [
        'proveedor_id' => $this->proveedor->id, 'numero' => '001-001-'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT), 'origen' => 'manual',
        'fecha_emision' => hoyLocal()->subDays(5), 'vence_el' => hoyLocal()->addDays(10), 'subtotal' => $total, 'iva' => '0.00', 'total' => $total,
        'estado' => 'por_aprobar', 'registrado_por' => $this->usuarios['tesorero']->id,
    ]));
    $this->aprobada = fn (string $total, string $vence = '+10 days', string $pagado = '0.00') => enCondominio($this->condominio, fn () => ($this->factura)($total, [
        'estado' => 'aprobada', 'nivel_aprobado' => 1, 'pagado' => $pagado, 'vence_el' => hoyLocal()->modify($vence),
    ]));
    $this->pagar = fn (string $quien, array $facturas, string $monto, array $extra = []) => ($this->como)($quien)->postJson('/api/v1/pagos-proveedor', $extra + [
        'proveedor_id' => $this->proveedor->id, 'facturas' => array_map(fn ($g) => $g->id, $facturas), 'monto' => $monto,
        'cuenta_bancaria_id' => $this->origen->id, 'fecha_pago' => hoyLocal()->toDateString(), 'referencia' => 'REF-'.random_int(1000, 9999),
    ]);
    $recargar = fn (Gasto $g) => enCondominio($this->condominio, fn () => $g->fresh());
    $this->recargar = $recargar;
});

it('una factura chica se aprueba en un solo nivel por la administración', function () {
    $g = ($this->factura)('350.00');

    $r = ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar", ['comentario' => 'Cotización más baja'])->assertOk();

    expect($r->json('data.estado'))->toBe('aprobada')
        ->and($r->json('data.aprobaciones.0'))->toMatchArray(['nivel' => 1, 'decision' => 'aprobada', 'comentario' => 'Cotización más baja', 'en_subrogacion' => false])
        ->and($r->json('data.registrada_por'))->toBe($this->usuarios['tesorero']->name)
        ->and($r->json('data.registrada_por_mi'))->toBeFalse()
        ->and(($this->como)('tesorero')->getJson("/api/v1/gastos/{$g->id}")->json('data.registrada_por_mi'))->toBeTrue();
});

it('quien registró la factura no la aprueba ni la rechaza', function () {
    $g = ($this->factura)('350.00', ['registrado_por' => $this->usuarios['admin']->id]);

    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertForbidden()->assertJsonPath('error.code', 'REGISTRO_PROPIO');
    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/rechazar", ['motivo' => 'No va'])->assertForbidden()->assertJsonPath('error.code', 'REGISTRO_PROPIO');
});

it('sobre el umbral pide un segundo nivel de la directiva; el vicepresidente queda como subrogación', function () {
    $g = ($this->factura)('1840.00');

    $r = ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertOk();
    expect($r->json('data.estado'))->toBe('por_aprobar')->and($r->json('data.nivel_pendiente'))->toBe(2)->and($r->json('data.requiere_segunda_aprobacion'))->toBeTrue();

    // La administración no da el 2.º nivel, ni el tesorero
    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertForbidden()->assertJsonPath('error.code', 'NIVEL_NO_PERMITIDO');
    ($this->como)('tesorero')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertForbidden();

    $r = ($this->como)('vice')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertOk();
    expect($r->json('data.estado'))->toBe('aprobada')
        ->and($r->json('data.aprobaciones.1'))->toMatchArray(['nivel' => 2, 'en_subrogacion' => true]);

    ($this->como)('presidente')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertStatus(409)->assertJsonPath('error.code', 'GASTO_NO_PENDIENTE');
});

it('el presidente da el segundo nivel sin subrogación', function () {
    $g = ($this->factura)('900.00', ['nivel_aprobado' => 1]);

    $r = ($this->como)('presidente')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertOk();

    expect($r->json('data.aprobaciones.0.en_subrogacion'))->toBeFalse();
});

it('la primera aprobación de una factura grande no la da la directiva', function () {
    $g = ($this->factura)('900.00');

    ($this->como)('presidente')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertForbidden()->assertJsonPath('error.code', 'NIVEL_NO_PERMITIDO');
});

it('rechazar exige motivo y cierra la factura', function () {
    $g = ($this->factura)('350.00');

    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/rechazar", [])->assertStatus(422);
    $r = ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/rechazar", ['motivo' => 'Monto no coincide con la cotización'])->assertOk();

    expect($r->json('data.estado'))->toBe('rechazada')->and($r->json('data.motivo_rechazo'))->toBe('Monto no coincide con la cotización');
    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertStatus(409);
});

it('el contador y el residente no aprueban ni pagan', function () {
    $g = ($this->factura)('350.00');
    ($this->como)('contador')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertForbidden();
    ($this->como)('contador')->postJson('/api/v1/pagos-proveedor', [])->assertForbidden();
    ($this->como)('contador')->getJson("/api/v1/gastos/{$g->id}")->assertOk();
});

it('paga con abono a la factura más antigua primero y deja pagada parcial', function () {
    $vieja = ($this->aprobada)('800.00', '-5 days');
    $nueva = ($this->aprobada)('350.00', '+20 days');

    $r = ($this->pagar)('tesorero', [$nueva, $vieja], '900.00')->assertCreated();
    expect($r->json('data.codigo'))->toBe('PP-0001')->and($r->json('data.monto'))->toBe('900.00');

    $vieja = ($this->recargar)($vieja);
    $nueva = ($this->recargar)($nueva);
    expect($vieja->estado)->toBe('pagada')->and($vieja->pagado)->toBe('800.00')
        ->and($nueva->estado)->toBe('aprobada')->and($nueva->pagado)->toBe('100.00');

    $lista = ($this->como)('contador')->getJson('/api/v1/gastos?filtro=por_pagar')->json('data');
    expect($lista[0])->toMatchArray(['saldo' => '250.00', 'pagada_parcial' => true]);

    // Segundo pago: numeración consecutiva y cierra la factura
    expect(($this->pagar)('tesorero', [$nueva], '250.00')->assertCreated()->json('data.codigo'))->toBe('PP-0002');
    expect(($this->recargar)($nueva)->estado)->toBe('pagada');
});

it('no paga más que el saldo, ni facturas sin aprobar, ni sin cuenta del proveedor', function () {
    $a = ($this->aprobada)('350.00');
    $sinAprobar = ($this->factura)('100.00');

    ($this->pagar)('tesorero', [$a], '350.01')->assertStatus(422)->assertJsonPath('error.code', 'MONTO_SUPERA_SALDO');
    ($this->pagar)('tesorero', [$sinAprobar], '100.00')->assertStatus(409)->assertJsonPath('error.code', 'FACTURA_NO_APROBADA');
    ($this->pagar)('tesorero', [$a], '10.00', ['fecha_pago' => hoyLocal()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_FUTURA');

    enCondominio($this->condominio, fn () => ProveedorCuenta::query()->update(['estado' => 'reemplazada']));
    ($this->pagar)('tesorero', [$a], '10.00')->assertStatus(409)->assertJsonPath('error.code', 'SIN_CUENTA_PROVEEDOR');
    expect(enCondominio($this->condominio, fn () => PagoProveedor::count()))->toBe(0);
});

it('quien aprobó una factura no la paga, salvo que el condominio lo permita', function () {
    $g = ($this->factura)('350.00');
    ($this->como)('admin')->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertOk();

    ($this->pagar)('admin', [$g], '350.00')->assertForbidden()->assertJsonPath('error.code', 'APROBADOR_NO_PAGA');

    enCondominio($this->condominio, fn () => ConfiguracionFinanzas::create(['umbral_segunda_aprobacion' => '500.00', 'aprobador_puede_pagar' => true]));
    ($this->pagar)('admin', [$g], '350.00')->assertCreated();
});

it('la referencia del banco no se repite para el mismo proveedor', function () {
    $a = ($this->aprobada)('100.00');
    $b = ($this->aprobada)('100.00');

    ($this->pagar)('tesorero', [$a], '100.00', ['referencia' => 'ABC-123'])->assertCreated();
    ($this->pagar)('tesorero', [$b], '100.00', ['referencia' => 'abc-123'])->assertStatus(409)->assertJsonPath('error.code', 'REFERENCIA_DUPLICADA');
});

it('guarda el comprobante y anula el pago devolviendo el saldo', function () {
    $g = ($this->aprobada)('350.00');

    $r = ($this->pagar)('tesorero', [$g], '350.00', ['comprobante' => UploadedFile::fake()->image('c.jpg')]);
    $r->assertCreated()->assertJsonPath('data.tiene_comprobante', true);
    $id = $r->json('data.id');
    expect(($this->recargar)($g)->estado)->toBe('pagada');

    ($this->como)('tesorero')->postJson("/api/v1/pagos-proveedor/{$id}/anular", [])->assertStatus(422);
    ($this->como)('tesorero')->postJson("/api/v1/pagos-proveedor/{$id}/anular", ['motivo' => 'Transferencia rechazada'])->assertOk()->assertJsonPath('data.estado', 'anulado');

    $g = ($this->recargar)($g);
    expect($g->estado)->toBe('aprobada')->and($g->pagado)->toBe('0.00');
    ($this->como)('tesorero')->postJson("/api/v1/pagos-proveedor/{$id}/anular", ['motivo' => 'Otra vez'])->assertStatus(409);

    expect(($this->como)('contador')->getJson('/api/v1/pagos-proveedor')->json('data.0'))->toMatchArray(['estado' => 'anulado', 'motivo_anulacion' => 'Transferencia rechazada']);
});

it('el tesorero ve las cuentas del condominio para elegir de dónde paga; el contador no', function () {
    expect(($this->como)('tesorero')->getJson('/api/v1/cuentas-bancarias')->assertOk()->json('data.0.banco'))->toBe('Banco Pichincha');
    ($this->como)('contador')->getJson('/api/v1/cuentas-bancarias')->assertForbidden();
});

it('aísla aprobaciones y pagos entre condominios', function () {
    $g = ($this->factura)('350.00');
    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();

    $this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id)
        ->postJson("/api/v1/gastos/{$g->id}/aprobar")->assertNotFound();
    expect(enCondominio($this->condominio, fn () => GastoAprobacion::count()))->toBe(0);
});
