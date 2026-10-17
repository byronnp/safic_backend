<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Services\ToleranciaBancaria;
use App\Modules\Plataforma\Models\Condominio;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
});

it('sin configuración devuelve los valores por defecto', function () {
    ($this->api)()->getJson('/api/v1/finanzas/configuracion')->assertOk()
        ->assertJsonPath('data', [
            'umbral_segunda_aprobacion' => '500.00', 'aprobador_puede_pagar' => false, 'tolerancia_bancaria' => '0.50',
            'dias_gracia' => 5, 'meses_restriccion' => 3, 'dias_aviso_restriccion' => 5,
        ]);
});

it('guarda las reglas y conserva lo que no viaja', function () {
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['umbral_segunda_aprobacion' => '1200', 'tolerancia_bancaria' => '0.10'])->assertOk()
        ->assertJsonPath('data.umbral_segunda_aprobacion', '1200.00')->assertJsonPath('data.tolerancia_bancaria', '0.10');
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['aprobador_puede_pagar' => true])->assertOk()
        ->assertJsonPath('data', [
            'umbral_segunda_aprobacion' => '1200.00', 'aprobador_puede_pagar' => true, 'tolerancia_bancaria' => '0.10',
            'dias_gracia' => 5, 'meses_restriccion' => 3, 'dias_aviso_restriccion' => 5,
        ]);

    expect(enCondominio($this->condominio, fn () => ConfiguracionFinanzas::count()))->toBe(1)
        ->and(enCondominio($this->condominio, fn () => app(ToleranciaBancaria::class)->centavos()))->toBe(10);
});

it('valida umbral y tolerancia', function () {
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['umbral_segunda_aprobacion' => '0'])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['umbral_segunda_aprobacion' => '10.555'])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['tolerancia_bancaria' => '5.01'])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['tolerancia_bancaria' => '-1'])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['tolerancia_bancaria' => '0'])->assertOk();
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['dias_gracia' => 31])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['meses_restriccion' => 0])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['dias_aviso_restriccion' => 0])->assertStatus(422);
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['dias_gracia' => 0, 'meses_restriccion' => 2, 'dias_aviso_restriccion' => 10])->assertOk()->assertJsonPath('data.meses_restriccion', 2);
});

it('solo quien edita el condominio las ve y cambia', function () {
    [, $tesorero] = usuarioConToken($this->condominio, Rol::Tesorero);
    cambiarDeUsuario();
    $como = fn () => $this->withToken($tesorero)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $como()->getJson('/api/v1/finanzas/configuracion')->assertForbidden();
    $como()->putJson('/api/v1/finanzas/configuracion', ['tolerancia_bancaria' => '1'])->assertForbidden();
});

it('cada condominio tiene sus propias reglas', function () {
    ($this->api)()->putJson('/api/v1/finanzas/configuracion', ['umbral_segunda_aprobacion' => '900'])->assertOk();

    $otro = Condominio::factory()->create();
    [, $token] = usuarioConToken($otro);
    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/finanzas/configuracion')
        ->assertJsonPath('data.umbral_segunda_aprobacion', '500.00');
});
