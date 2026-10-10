<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['total_unidades' => 50]);
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

    // Septiembre-like: el mes anterior ya terminó, con dos unidades y sus cuotas emitidas
    $this->mes = hoyLocal()->startOfMonth()->subMonth();
    $this->periodo = $this->mes->format('Y-m');
    [$this->u1, $this->u2] = enCondominio($this->condominio, fn () => [
        Unidad::factory()->create(['cuota_mensual' => '80.00']),
        Unidad::factory()->create(['cuota_mensual' => '80.00']),
    ]);
    $this->emitir = function () {
        enCondominio($this->condominio, function () {
            PeriodoFinanciero::create(['periodo' => $this->mes->toDateString(), 'estado' => 'abierto', 'emitido_en' => now()]);
            foreach ([$this->u1, $this->u2] as $u) {
                Cuota::create(['unidad_id' => $u->id, 'periodo' => $this->mes->toDateString(), 'concepto' => 'ordinaria', 'monto' => '80.00', 'pagado' => '80.00', 'vence_el' => $this->mes->day(10)->toDateString()]);
            }
        });
    };
    $this->estadoDe = fn (string $clave, array $v) => collect($v)->firstWhere('clave', $clave)['estado'];
});

it('un mes sin emitir no se puede cerrar', function () {
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_SIN_EMITIR');
    expect(($this->como)('admin')->getJson("/api/v1/finanzas/periodos/{$this->periodo}/cierre")->assertOk()->json('data.puede_cerrar'))->toBeFalse();
});

it('la lista de verificación bloquea con pagos sin revisar y avisa de facturas pendientes', function () {
    ($this->emitir)();
    enCondominio($this->condominio, function () {
        Pago::create(['unidad_id' => $this->u1->id, 'metodo' => 'transferencia', 'monto' => '80.00', 'fecha_pago' => $this->mes->day(20)->toDateString(), 'estado' => 'pendiente']);
        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Ascensores']);
        Gasto::create(['proveedor_id' => $p->id, 'numero' => '001-001-000000001', 'origen' => 'manual', 'fecha_emision' => $this->mes->day(5)->toDateString(), 'vence_el' => $this->mes->day(25)->toDateString(), 'subtotal' => '100.00', 'iva' => '0.00', 'total' => '100.00', 'estado' => 'por_aprobar']);
    });

    $r = ($this->como)('contador')->getJson("/api/v1/finanzas/periodos/{$this->periodo}/cierre")->assertOk();
    $v = $r->json('data.verificaciones');

    expect(($this->estadoDe)('cuotas', $v))->toBe('listo')
        ->and(($this->estadoDe)('pagos', $v))->toBe('bloquea')
        ->and(($this->estadoDe)('facturas_por_aprobar', $v))->toBe('revisar')
        ->and($r->json('data.puede_cerrar'))->toBeFalse()
        ->and($r->json('data.motivo_bloqueo'))->toContain('1 comprobante');
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertStatus(409)->assertJsonPath('error.code', 'CIERRE_BLOQUEADO');
});

it('no se cierra el mes en curso', function () {
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => hoyLocal()->startOfMonth()->toDateString(), 'estado' => 'abierto', 'emitido_en' => now()]));

    ($this->como)('admin')->postJson('/api/v1/finanzas/periodos/'.hoyLocal()->format('Y-m').'/cerrar')->assertStatus(409)->assertJsonPath('error.code', 'CIERRE_BLOQUEADO');
});

it('cierra el mes, guarda el resumen y deja el mes de solo lectura', function () {
    ($this->emitir)();

    $r = ($this->como)('tesorero')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertOk();
    expect($r->json('data.estado'))->toBe('cerrado')
        ->and($r->json('data.cierre.cerrado_por'))->toBe($this->usuarios['tesorero']->name)
        ->and($r->json('data.resumen'))->toMatchArray(['emitido' => '160.00', 'cobrado' => '160.00', 'gastos_pagados' => '0.00']);

    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_YA_CERRADO');

    // Solo lectura: nada nuevo con fecha en ese mes
    enCondominio($this->condominio, fn () => ConfiguracionCobro::create(['metodo' => 'general', 'cuota_general' => '80.00', 'dia_vencimiento' => 10, 'aplica_desde' => $this->mes->subMonths(2)->toDateString()]));
    ($this->como)('admin')->postJson('/api/v1/finanzas/periodos', ['periodo' => $this->periodo])->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
    ($this->como)('admin')->postJson("/api/v1/unidades/{$this->u1->id}/pagos-efectivo", ['monto' => '10.00', 'fecha' => $this->mes->day(28)->toDateString()])->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
    ($this->como)('tesorero')->postJson('/api/v1/gastos', [
        'proveedor_id' => 1, 'numero' => '001-001-000000009', 'fecha_emision' => $this->mes->day(10)->toDateString(), 'subtotal' => '10.00', 'con_iva' => false,
    ])->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
});

it('un pago con fecha en un mes cerrado no se aprueba ni se paga a proveedores', function () {
    ($this->emitir)();
    $pagoPendiente = enCondominio($this->condominio, fn () => Pago::create(['unidad_id' => $this->u2->id, 'metodo' => 'transferencia', 'monto' => '10.00', 'fecha_pago' => $this->mes->day(20)->toDateString(), 'estado' => 'pendiente']));
    enCondominio($this->condominio, fn () => Pago::whereKey($pagoPendiente->id)->update(['estado' => 'aprobado']));
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertOk();
    enCondominio($this->condominio, fn () => Pago::whereKey($pagoPendiente->id)->update(['estado' => 'pendiente']));

    ($this->como)('admin')->postJson("/api/v1/pagos/{$pagoPendiente->id}/aprobar")->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
});

it('reabrir lo hace solo el administrador, con motivo, y solo el último mes cerrado', function () {
    ($this->emitir)();
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertOk();

    ($this->como)('tesorero')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/reabrir", ['motivo' => 'Corregir un pago'])->assertForbidden();
    ($this->como)('contador')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/cerrar")->assertForbidden();
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/reabrir", [])->assertStatus(422);

    $r = ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/reabrir", ['motivo' => 'Corregir un pago'])->assertOk();
    expect($r->json('data.estado'))->toBe('abierto')
        ->and($r->json('data.reapertura'))->toMatchArray(['motivo' => 'Corregir un pago', 'reabierto_por' => $this->usuarios['admin']->name]);
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/reabrir", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_NO_CERRADO');

    // Con un mes posterior cerrado no se reabre el anterior
    enCondominio($this->condominio, fn () => PeriodoFinanciero::whereKey(PeriodoFinanciero::first()->id)->update(['estado' => 'cerrado']));
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => $this->mes->addMonth()->toDateString(), 'estado' => 'cerrado', 'emitido_en' => now()]));
    ($this->como)('admin')->postJson("/api/v1/finanzas/periodos/{$this->periodo}/reabrir", ['motivo' => 'Corregir'])->assertStatus(409)->assertJsonPath('error.code', 'REABRIR_EL_ULTIMO');
});

it('cada condominio cierra sus propios meses', function () {
    ($this->emitir)();
    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();

    $r = $this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id)->getJson("/api/v1/finanzas/periodos/{$this->periodo}/cierre")->assertOk();
    expect($r->json('data.emitido'))->toBeFalse();
});
