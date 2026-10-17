<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\CajaChicaMovimiento;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);
    $this->condominio = Condominio::factory()->create();
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'mantenimiento' => Rol::Mantenimiento, 'residente' => Rol::Residente] as $clave => $rol) {
        [$u] = usuarioConToken($this->condominio, $rol);
        $this->usuarios[$clave] = $u;
    }
    $this->como = function (string $clave) {
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($this->usuarios[$clave]->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->hoy = hoyLocal()->toDateString();
    $this->configurar = fn (string $fondo = '200.00') => ($this->como)('tesorero')->putJson('/api/v1/finanzas/caja-chica', ['fondo' => $fondo, 'responsable_nombre' => 'Marco Salas']);
    $this->gastar = fn (string $monto = '35.50', string $quien = 'mantenimiento') => ($this->como)($quien)->post('/api/v1/finanzas/caja-chica/movimientos', [
        'concepto' => 'Focos y cinta', 'comercio' => 'Ferretería Sur', 'monto' => $monto, 'fecha' => $this->hoy,
        'respaldo' => UploadedFile::fake()->image('recibo.jpg'),
    ], ['Accept' => 'application/json']);
});

it('sin configurar muestra la caja vacía y no deja registrar gastos', function () {
    ($this->como)('tesorero')->getJson('/api/v1/finanzas/caja-chica')->assertOk()->assertJsonPath('data.configurada', false);
    ($this->gastar)()->assertStatus(409)->assertJsonPath('error.code', 'CAJA_SIN_CONFIGURAR');
});

it('fija el fondo y registra gastos con respaldo, descontando de lo disponible', function () {
    ($this->configurar)()->assertOk()->assertJsonPath('data.disponible', '200.00');
    $r = ($this->gastar)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['fondo' => '200.00', 'gastado' => '35.50', 'disponible' => '164.50', 'responsable' => 'Marco Salas'])
        ->and($r->json('data.movimientos.0.respaldo_url'))->not->toBeNull();
});

it('rechaza un gasto que supera lo disponible, sin respaldo o con fecha futura', function () {
    ($this->configurar)('50.00');
    ($this->gastar)('60.00')->assertStatus(422)->assertJsonPath('error.code', 'FONDO_INSUFICIENTE');

    ($this->como)('mantenimiento')->post('/api/v1/finanzas/caja-chica/movimientos', ['concepto' => 'Algo', 'monto' => '5.00', 'fecha' => $this->hoy], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDACION');

    ($this->como)('mantenimiento')->post('/api/v1/finanzas/caja-chica/movimientos', [
        'concepto' => 'Algo', 'monto' => '5.00', 'fecha' => hoyLocal()->addDay()->toDateString(), 'respaldo' => UploadedFile::fake()->image('r.png'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_FUTURA');

    expect(enCondominio($this->condominio, fn () => CajaChicaMovimiento::count()))->toBe(0);
});

it('el fondo no puede quedar por debajo de lo ya gastado', function () {
    ($this->configurar)();
    ($this->gastar)('80.00');

    ($this->configurar)('50.00')->assertStatus(422)->assertJsonPath('error.code', 'FONDO_MENOR_AL_GASTO');
    ($this->configurar)('100.00')->assertOk()->assertJsonPath('data.disponible', '20.00');
});

it('al reponer crea un gasto por aprobar a nombre de la caja chica y deja el fondo completo', function () {
    ($this->configurar)();
    ($this->gastar)('35.50');
    ($this->gastar)('14.50');

    $r = ($this->como)('tesorero')->postJson('/api/v1/finanzas/caja-chica/reponer')->assertCreated();

    expect($r->json('data'))->toMatchArray(['gastado' => '0.00', 'disponible' => '200.00'])
        ->and($r->json('data.movimientos'))->toBe([])
        ->and($r->json('data.ultima_reposicion.monto'))->toBe('50.00')
        ->and($r->json('meta.gasto_numero'))->toMatch('/^CC-\d{4}-\d{3}$/');

    $gasto = enCondominio($this->condominio, fn () => Gasto::query()->findOrFail($r->json('meta.gasto_id')));
    expect($gasto->estado)->toBe('por_aprobar')->and($gasto->origen)->toBe('caja_chica')->and((string) $gasto->total)->toBe('50.00');

    ($this->como)('tesorero')->postJson('/api/v1/finanzas/caja-chica/reponer')->assertStatus(409)->assertJsonPath('error.code', 'SIN_MOVIMIENTOS');
});

it('respeta los permisos', function () {
    ($this->configurar)();
    ($this->como)('residente')->getJson('/api/v1/finanzas/caja-chica')->assertForbidden();
    ($this->gastar)('5.00', 'residente')->assertForbidden();
    ($this->como)('mantenimiento')->putJson('/api/v1/finanzas/caja-chica', ['fondo' => '300.00', 'responsable_nombre' => 'Otro'])->assertForbidden();
    ($this->como)('mantenimiento')->postJson('/api/v1/finanzas/caja-chica/reponer')->assertForbidden();
    ($this->como)('mantenimiento')->getJson('/api/v1/finanzas/caja-chica')->assertOk();
});
