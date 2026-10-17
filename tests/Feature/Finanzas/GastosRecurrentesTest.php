<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Mail\FacturaPorAdjuntarMail;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use App\Modules\Finanzas\Models\GastoRecurrente;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\RecurrenteOcurrencia;
use App\Modules\Finanzas\Services\RecurrentesService;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create();
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'contador' => Rol::Contador] as $clave => $rol) {
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
    $this->hoy = hoyLocal();
    $this->periodo = $this->hoy->format('Y-m');
    $this->proveedor = enCondominio($this->condominio, fn () => Proveedor::create(['ruc' => '0991122334001', 'razon_social' => 'Limpieza Total']));
    // Un día que ya pasó este mes, o el primero si hoy es 1
    $this->diaPasado = max(1, $this->hoy->day - 1);
    $this->diaFuturo = min(28, $this->hoy->day + 1);
    $this->crear = fn (array $extra = []) => ($this->como)('tesorero')->postJson('/api/v1/finanzas/recurrentes', $extra + [
        'nombre' => 'Limpieza', 'proveedor_id' => $this->proveedor->id, 'categoria' => 'Limpieza', 'monto' => '1250.00', 'dia_mes' => $this->diaPasado,
    ]);
    $this->factura = fn (string $total = '1250.00', array $extra = []) => enCondominio($this->condominio, fn () => Gasto::create($extra + [
        'proveedor_id' => $this->proveedor->id, 'numero' => '001-001-'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT), 'origen' => 'manual',
        'fecha_emision' => $this->hoy->toDateString(), 'vence_el' => $this->hoy->addDays(10)->toDateString(), 'subtotal' => $total, 'iva' => '0.00', 'total' => $total,
        'estado' => 'por_aprobar', 'registrado_por' => $this->usuarios['tesorero']->id,
    ]));
});

it('crea un gasto recurrente; si ya pasó su día, se crea la del mes y se avisa que falta la factura', function () {
    $r = ($this->crear)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['nombre' => 'Limpieza', 'monto' => '1250.00', 'dia_mes' => $this->diaPasado, 'activo' => true])
        ->and($r->json('data.mes.estado'))->toBe('falta_factura');
    Mail::assertQueued(FacturaPorAdjuntarMail::class, fn ($m) => $m->gasto === 'Limpieza' && $m->monto === '1250.00');
});

it('uno cuyo día aún no llega queda programado y no avisa', function () {
    $r = ($this->crear)(['dia_mes' => $this->diaFuturo > $this->hoy->day ? $this->diaFuturo : 28])->assertCreated();

    if ($this->hoy->day < 28) {
        expect($r->json('data.mes.estado'))->toBe('programado');
        Mail::assertNothingQueued();
    }
});

it('la tarea diaria crea la ocurrencia del mes una sola vez', function () {
    enCondominio($this->condominio, fn () => GastoRecurrente::create(['nombre' => 'Guardianía', 'proveedor_id' => $this->proveedor->id, 'monto' => '4480.00', 'dia_mes' => 1, 'activo' => true]));

    $generar = fn () => enCondominio($this->condominio, fn () => app(RecurrentesService::class)->generar());

    expect($generar())->toBe(1)->and($generar())->toBe(0);
    expect(enCondominio($this->condominio, fn () => RecurrenteOcurrencia::count()))->toBe(1);
    Mail::assertQueued(FacturaPorAdjuntarMail::class, 2); // uno al administrador y otro al tesorero, una sola vez
});

it('uno pausado no se crea; el monto variable no admite aprobación automática', function () {
    $id = ($this->crear)(['monto' => null, 'nombre' => 'Luz áreas comunes'])->assertCreated()->json('data.id');
    ($this->crear)(['monto' => null, 'aprobacion_automatica' => true])->assertStatus(422)->assertJsonPath('error.code', 'APROBACION_SIN_MONTO');

    ($this->como)('tesorero')->patchJson("/api/v1/finanzas/recurrentes/{$id}", ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);
    enCondominio($this->condominio, fn () => RecurrenteOcurrencia::query()->delete());

    $r = ($this->como)('contador')->getJson('/api/v1/finanzas/recurrentes')->assertOk();
    expect(collect($r->json('data'))->firstWhere('id', $id)['mes']['estado'])->toBe('pausado')
        ->and(enCondominio($this->condominio, fn () => app(RecurrentesService::class)->generar()))->toBe(0);
});

it('el listado resume lo comprometido (sin variables), lo que falta y el próximo', function () {
    ($this->crear)(['nombre' => 'Limpieza', 'monto' => '1250.00'])->assertCreated();
    ($this->crear)(['nombre' => 'Luz', 'monto' => null])->assertCreated();
    if ($this->hoy->day < 28) {
        ($this->crear)(['nombre' => 'Ascensores', 'monto' => '380.00', 'dia_mes' => 28])->assertCreated();
    }

    $r = ($this->como)('contador')->getJson('/api/v1/finanzas/recurrentes')->assertOk();

    expect($r->json('meta.resumen.por_adjuntar'))->toBe(2)
        ->and($r->json('meta.resumen.comprometido'))->toBe($this->hoy->day < 28 ? '1630.00' : '1250.00');
    if ($this->hoy->day < 28) {
        expect($r->json('meta.resumen.proximo'))->toBe(['nombre' => 'Ascensores', 'fecha' => $this->hoy->startOfMonth()->day(28)->toDateString()]);
    }
});

it('vincula la factura de un mes; el proveedor debe coincidir y una factura va a un solo gasto', function () {
    $id = ($this->crear)()->assertCreated()->json('data.id');
    $factura = ($this->factura)('1000.00');
    $ajena = enCondominio($this->condominio, function () {
        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Otro']);

        return Gasto::create(['proveedor_id' => $p->id, 'numero' => '001-001-000000999', 'origen' => 'manual', 'fecha_emision' => $this->hoy->toDateString(), 'vence_el' => $this->hoy->addDays(5)->toDateString(), 'subtotal' => '10.00', 'iva' => '0.00', 'total' => '10.00', 'estado' => 'por_aprobar']);
    });

    ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $ajena->id])->assertStatus(422)->assertJsonPath('error.code', 'PROVEEDOR_DISTINTO');

    $v = ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $factura->id])->assertOk();
    expect($v->json('data.mes'))->toMatchArray(['estado' => 'factura_recibida', 'gasto_id' => $factura->id, 'aprobada_automaticamente' => false]);
    expect(enCondominio($this->condominio, fn () => $factura->fresh()->estado))->toBe('por_aprobar'); // el monto no coincide y no hay aprobación automática

    ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $factura->id])->assertStatus(409)->assertJsonPath('error.code', 'YA_TIENE_FACTURA');
});

it('aprueba el nivel 1 sola si la factura coincide con el monto', function () {
    $id = ($this->crear)(['monto' => '400.00', 'aprobacion_automatica' => true])->assertCreated()->json('data.id');
    $factura = ($this->factura)('400.00');

    $v = ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $factura->id])->assertOk();

    expect($v->json('meta.aprobada_automaticamente'))->toBeTrue();
    $g = enCondominio($this->condominio, fn () => $factura->fresh());
    expect($g->estado)->toBe('aprobada')->and($g->nivel_aprobado)->toBe(1);
    $a = enCondominio($this->condominio, fn () => GastoAprobacion::firstOrFail());
    expect($a->user_id)->toBeNull()->and($a->comentario)->toContain('Aprobación automática');
});

it('sobre el umbral la aprobación automática solo da el nivel 1: falta la directiva', function () {
    enCondominio($this->condominio, fn () => ConfiguracionFinanzas::create(['umbral_segunda_aprobacion' => '500.00']));
    $id = ($this->crear)(['monto' => '4480.00', 'aprobacion_automatica' => true])->assertCreated()->json('data.id');
    $factura = ($this->factura)('4480.00');

    ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $factura->id])->assertOk();

    $g = enCondominio($this->condominio, fn () => $factura->fresh());
    expect($g->estado)->toBe('por_aprobar')->and($g->nivel_aprobado)->toBe(1);
});

it('con otro monto no aprueba sola; y no vincula en un mes cerrado', function () {
    $id = ($this->crear)(['monto' => '400.00', 'aprobacion_automatica' => true])->assertCreated()->json('data.id');
    $distinta = ($this->factura)('401.00');

    $v = ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $distinta->id])->assertOk();
    expect($v->json('meta.aprobada_automaticamente'))->toBeFalse()
        ->and(enCondominio($this->condominio, fn () => $distinta->fresh()->estado))->toBe('por_aprobar');

    $otro = ($this->crear)(['nombre' => 'Otro'])->assertCreated()->json('data.id');
    $vieja = ($this->factura)('50.00', ['fecha_emision' => $this->hoy->subMonths(2)->startOfMonth()->toDateString()]);
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => $this->hoy->subMonths(2)->startOfMonth()->toDateString(), 'estado' => 'cerrado', 'emitido_en' => now()]));
    ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$otro}/factura", ['periodo' => $this->periodo, 'gasto_id' => $vieja->id])->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
});

it('lista las facturas candidatas del proveedor, sin las ya vinculadas', function () {
    $id = ($this->crear)()->assertCreated()->json('data.id');
    $a = ($this->factura)('100.00');
    $b = ($this->factura)('200.00');
    ($this->como)('tesorero')->postJson("/api/v1/finanzas/recurrentes/{$id}/factura", ['periodo' => $this->periodo, 'gasto_id' => $a->id])->assertOk();

    $c = ($this->como)('tesorero')->getJson("/api/v1/finanzas/recurrentes/{$id}/facturas")->assertOk()->json('data');

    expect(array_column($c, 'id'))->toBe([$b->id]);
});

it('quién ve y quién gestiona; cada condominio ve lo suyo', function () {
    ($this->crear)()->assertCreated();
    ($this->como)('contador')->getJson('/api/v1/finanzas/recurrentes')->assertOk();
    ($this->como)('contador')->postJson('/api/v1/finanzas/recurrentes', ['nombre' => 'X Y Z', 'proveedor_id' => $this->proveedor->id, 'dia_mes' => 5])->assertForbidden();
    ($this->como)('admin')->postJson('/api/v1/finanzas/recurrentes', ['nombre' => 'X Y Z', 'proveedor_id' => $this->proveedor->id, 'dia_mes' => 29])->assertStatus(422);
    ($this->como)('admin')->postJson('/api/v1/finanzas/recurrentes', ['nombre' => 'X Y Z', 'proveedor_id' => 99999, 'dia_mes' => 5])->assertStatus(422)->assertJsonPath('error.code', 'PROVEEDOR_INVALIDO');

    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();
    expect($this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/finanzas/recurrentes')->json('data'))->toBe([]);
});
