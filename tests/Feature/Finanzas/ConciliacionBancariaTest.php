<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ReglaConciliacion;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['codigo' => 'JV']);
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

    $this->mes = hoyLocal()->startOfMonth()->subMonth();
    $this->periodo = $this->mes->format('Y-m');
    $this->cuenta = enCondominio($this->condominio, fn () => CuentaBancaria::create(['banco' => 'Banco Pichincha', 'tipo' => 'corriente', 'numero' => '2100124821', 'titular' => 'Condominio', 'es_principal' => true]));
    [, , $this->unidad] = residenteDe($this->condominio, 'A-201');

    $d = fn (int $dia) => $this->mes->day($dia)->format('d/m/Y');
    $this->csv = fn (array $filas) => UploadedFile::fake()->createWithContent('estado.csv', "Fecha;Descripción;Documento;Débito;Crédito;Saldo\n".implode("\n", $filas)."\n");
    $this->importar = fn ($archivo, array $extra = []) => ($this->como)('tesorero')->post('/api/v1/conciliacion/estados', $extra + [
        'cuenta_bancaria_id' => $this->cuenta->id, 'periodo' => $this->periodo, 'archivo' => $archivo,
    ], ['Accept' => 'application/json']);
    $this->pagoResidente = fn (string $monto = '80.00', array $extra = []) => enCondominio($this->condominio, fn () => Pago::create($extra + [
        'unidad_id' => $this->unidad->id, 'metodo' => 'transferencia', 'monto' => $monto, 'cuenta_bancaria_id' => $this->cuenta->id,
        'numero_comprobante' => '555001', 'fecha_pago' => $this->mes->day(2)->toDateString(), 'estado' => 'aprobado',
    ]));
    $this->filas = fn () => [
        $this->mes->day(2)->format('d/m/Y').';TRANSF RECIBIDA JV-A201;118801245;;80,00;1.080,00',
        $this->mes->day(21)->format('d/m/Y').';COMISION TRANSF INTERB;771204596;0,41;;1.079,59',
        $this->mes->day(28)->format('d/m/Y').';CARGO MISTERIOSO;000999;12,00;;1.067,59',
    ];
});

it('importa el estado de cuenta y clasifica: automático por código y monto, comisión sugerida, el resto sin identificar', function () {
    ($this->pagoResidente)();

    $r = ($this->importar)(($this->csv)(($this->filas)()))->assertCreated();

    expect($r->json('meta'))->toMatchArray(['nuevos' => 3, 'duplicados' => 0, 'fuera_del_mes' => 0])
        ->and($r->json('data.conteos'))->toMatchArray(['automaticos' => 1, 'sugeridos' => 1, 'sin_identificar' => 1])
        ->and($r->json('data.automaticos.0'))->toMatchArray(['tipo' => 'pago_residente', 'estado' => 'automatico'])
        ->and($r->json('data.sugeridos.0.propuesta'))->toMatchArray(['tipo' => 'comision'])
        ->and($r->json('data.cuadre'))->toMatchArray(['saldo_inicial' => '1000.00', 'saldo_banco' => '1067.59', 'archivo_cuadra' => true]);
});

it('no vuelve a importar las mismas líneas', function () {
    ($this->pagoResidente)();
    ($this->importar)(($this->csv)(($this->filas)()))->assertCreated();

    $r = ($this->importar)(($this->csv)(($this->filas)()))->assertCreated();

    expect($r->json('meta'))->toMatchArray(['nuevos' => 0, 'duplicados' => 3])
        ->and(enCondominio($this->condominio, fn () => MovimientoBancario::count()))->toBe(3);
});

it('una línea con el monto del pago pero sin código ni referencia queda sugerida', function () {
    ($this->pagoResidente)('80.00', ['numero_comprobante' => '777']);

    $r = ($this->importar)(($this->csv)([$this->mes->day(2)->format('d/m/Y').';TRANSF MORA JIMENEZ D;004518823;;80,00;1.080,00']))->assertCreated();

    expect($r->json('data.sugeridos.0.propuesta'))->toMatchArray(['tipo' => 'pago_residente'])
        ->and($r->json('data.sugeridos.0.propuesta.texto'))->toContain('A-201');
});

it('un débito cruza con el pago a proveedor por referencia', function () {
    $pago = enCondominio($this->condominio, function () {
        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Seguridad']);

        return PagoProveedor::create(['numero' => 1, 'proveedor_id' => $p->id, 'cuenta_bancaria_id' => $this->cuenta->id, 'monto' => '3450.00', 'fecha_pago' => $this->mes->day(5)->toDateString(), 'referencia' => '4430112', 'estado' => 'registrado']);
    });

    $r = ($this->importar)(($this->csv)([$this->mes->day(5)->format('d/m/Y').';PAGO SERV SEGURIDAD;004430112;3.450,00;;1.000,00']))->assertCreated();

    expect($r->json('data.automaticos.0'))->toMatchArray(['tipo' => 'pago_proveedor', 'pago_proveedor_id' => $pago->id]);
});

it('confirma una sugerencia vinculando el pago; el monto debe ser el mismo y un pago va a una sola línea', function () {
    ($this->pagoResidente)('80.00', ['numero_comprobante' => '777']);
    $r = ($this->importar)(($this->csv)([$this->mes->day(2)->format('d/m/Y').';TRANSF MORA JIMENEZ D;004518823;;80,00;1.080,00']))->assertCreated();
    $movimiento = $r->json('data.sugeridos.0.id');
    $pagoId = $r->json('data.sugeridos.0.propuesta.id');
    $distinto = ($this->pagoResidente)('99.00', ['numero_comprobante' => '888']);

    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$movimiento}/vincular", ['tipo' => 'pago_residente', 'pago_id' => $distinto->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'MONTO_DISTINTO');
    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$movimiento}/vincular", ['tipo' => 'pago_proveedor', 'pago_id' => $pagoId])
        ->assertStatus(422)->assertJsonPath('error.code', 'DIRECCION_INVALIDA');

    $v = ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$movimiento}/vincular", ['tipo' => 'pago_residente', 'pago_id' => $pagoId])->assertOk();
    expect($v->json('data.conteos'))->toMatchArray(['automaticos' => 1, 'sugeridos' => 0])
        ->and($v->json('data.cuadre.pagos_residentes'))->toBe('80.00');
    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$movimiento}/vincular", ['tipo' => 'pago_residente', 'pago_id' => $pagoId])->assertStatus(409);
});

it('clasifica una comisión y guarda una regla que resuelve las parecidas', function () {
    $r = ($this->importar)(($this->csv)([
        $this->mes->day(21)->format('d/m/Y').';COSTO MANT CTA CTE 0001;000931;0,78;;999,22',
        $this->mes->day(28)->format('d/m/Y').';COSTO MANT CTA CTE 0002;000932;0,78;;998,44',
    ]), ['saldo_inicial' => '1000.00'])->assertCreated();
    $id = $r->json('data.sugeridos.0.id');

    $c = ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$id}/clasificar", ['tipo' => 'comision', 'guardar_regla' => true])->assertOk();

    expect($c->json('data.conteos'))->toMatchArray(['automaticos' => 2, 'sugeridos' => 0, 'sin_identificar' => 0])
        ->and($c->json('data.cuadre.comisiones'))->toBe('-1.56')
        ->and(enCondominio($this->condominio, fn () => ReglaConciliacion::first()->patron))->toBe('COSTO MANT CTA CTE');
    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$id}/clasificar", ['tipo' => 'interes'])->assertStatus(409);
});

it('una regla guardada clasifica sola al importar otro mes', function () {
    enCondominio($this->condominio, fn () => ReglaConciliacion::create(['patron' => 'INTERES GANADO', 'tipo' => 'interes']));

    $r = ($this->importar)(($this->csv)([$this->mes->day(30)->format('d/m/Y').';INTERES GANADO;000930;;0,37;1.000,37']))->assertCreated();

    expect($r->json('data.automaticos.0'))->toMatchArray(['tipo' => 'interes', 'estado' => 'automatico']);
});

it('el cuadre muestra la diferencia, y un ajuste con motivo la cierra', function () {
    $r = ($this->importar)(($this->csv)([$this->mes->day(28)->format('d/m/Y').';CARGO MISTERIOSO;000999;12,00;;988,00']), ['saldo_inicial' => '1000.00'])->assertCreated();
    $estadoId = $r->json('data.id');
    expect($r->json('data.cuadre'))->toMatchArray(['saldo_app' => '1000.00', 'saldo_banco' => '988.00', 'diferencia' => '-12.00']);

    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/estados/{$estadoId}/ajustes", ['monto' => '-12.00', 'motivo' => ''])->assertStatus(422);
    $a = ($this->como)('tesorero')->postJson("/api/v1/conciliacion/estados/{$estadoId}/ajustes", ['monto' => '-12.00', 'motivo' => 'Cobro del banco no identificado'])->assertCreated();

    expect($a->json('data.cuadre'))->toMatchArray(['ajustes' => '-12.00', 'diferencia' => '0.00']);
});

it('deshacer una línea la libera y la vuelve a clasificar', function () {
    ($this->pagoResidente)();
    $r = ($this->importar)(($this->csv)(($this->filas)()))->assertCreated();
    $id = $r->json('data.automaticos.0.id');

    ($this->como)('tesorero')->postJson("/api/v1/conciliacion/movimientos/{$id}/deshacer")->assertOk();

    // Al volver a clasificar, el mismo pago se ofrece de nuevo
    $m = enCondominio($this->condominio, fn () => MovimientoBancario::find($id));
    expect($m->estado)->toBe('automatico')->and($m->pago_id)->not->toBeNull();
    ($this->como)('tesorero')->postJson('/api/v1/conciliacion/movimientos/'.enCondominio($this->condominio, fn () => MovimientoBancario::where('estado', 'sugerido')->value('id')).'/deshacer')->assertStatus(409);
});

it('el cierre del mes usa la conciliación: bloquea con líneas pendientes y se desbloquea al resolverlas', function () {
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => $this->mes->toDateString(), 'estado' => 'abierto', 'emitido_en' => now()]));
    $ver = fn () => collect(($this->como)('contador')->getJson("/api/v1/finanzas/periodos/{$this->periodo}/cierre")->json('data.verificaciones'))->firstWhere('clave', "conciliacion_{$this->cuenta->id}");

    expect($ver()['estado'])->toBe('revisar'); // aún sin estado de cuenta importado

    $r = ($this->importar)(($this->csv)([$this->mes->day(28)->format('d/m/Y').';CARGO MISTERIOSO;000999;12,00;;988,00']), ['saldo_inicial' => '1000.00'])->assertCreated();
    expect($ver()['estado'])->toBe('bloquea')->and($ver()['detalle'])->toContain('1 movimiento sin identificar');

    ($this->como)('tesorero')->postJson('/api/v1/conciliacion/movimientos/'.$r->json('data.sin_identificar.0.id').'/clasificar', ['tipo' => 'otro'])->assertOk();
    // Con la línea clasificada el saldo de la app (1.000,00 − 12,00) coincide con el banco
    expect($ver())->toMatchArray(['estado' => 'listo']);
});

it('un pago a proveedor conciliado no se anula', function () {
    $pago = enCondominio($this->condominio, function () {
        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Seguridad']);

        return PagoProveedor::create(['numero' => 1, 'proveedor_id' => $p->id, 'cuenta_bancaria_id' => $this->cuenta->id, 'monto' => '50.00', 'fecha_pago' => $this->mes->day(5)->toDateString(), 'referencia' => 'ABC123', 'estado' => 'registrado']);
    });
    ($this->importar)(($this->csv)([$this->mes->day(5)->format('d/m/Y').';PAGO PROVEEDOR;ABC123;50,00;;950,00']))->assertCreated();

    ($this->como)('admin')->postJson("/api/v1/pagos-proveedor/{$pago->id}/anular", ['motivo' => 'Error'])->assertStatus(409)->assertJsonPath('error.code', 'PAGO_CONCILIADO');
});

it('valida el archivo, el mes y los permisos', function () {
    ($this->como)('contador')->get('/api/v1/conciliacion/estados', ['Accept' => 'application/json'])->assertOk();
    ($this->como)('contador')->post('/api/v1/conciliacion/estados', ['cuenta_bancaria_id' => $this->cuenta->id, 'periodo' => $this->periodo, 'archivo' => ($this->csv)([])], ['Accept' => 'application/json'])->assertForbidden();

    ($this->importar)(UploadedFile::fake()->createWithContent('x.csv', "hola\nmundo\n"))->assertStatus(422)->assertJsonPath('error.code', 'ESTADO_FORMATO_DESCONOCIDO');
    ($this->importar)(($this->csv)([hoyLocal()->subMonths(5)->format('d/m/Y').';ANTIGUO;1;;1,00;1,00']))->assertStatus(422)->assertJsonPath('error.code', 'ESTADO_FUERA_DEL_MES');
    ($this->importar)(($this->csv)(($this->filas)()), ['cuenta_bancaria_id' => 9999])->assertStatus(422)->assertJsonPath('error.code', 'CUENTA_INVALIDA');
});

it('cada condominio ve solo sus estados de cuenta', function () {
    ($this->importar)(($this->csv)(($this->filas)()))->assertCreated();
    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();

    $this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/conciliacion/estados')->assertOk()->assertJsonPath('data', []);
});
