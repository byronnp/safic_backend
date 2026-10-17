<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Actions\ExportarReporteAction;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\EstadoCuentaBancario;
use App\Modules\Finanzas\Models\Exportacion;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Models\PagoProveedorAplicacion;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Plataforma\Models\Condominio;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle']);
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'contador' => Rol::Contador, 'guardia' => Rol::Guardia] as $clave => $rol) {
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

    [, , $this->a] = residenteDe($this->condominio, 'A-101');
    [, , $this->b] = residenteDe($this->condominio, 'B-202');
    // A debe 3 meses; B pagó lo del mes pasado
    foreach ([3, 2, 1] as $m) {
        cuotaDe($this->condominio, $this->a, $m);
    }
    cuotaDe($this->condominio, $this->b, 1, '80.00', '80.00');
    $this->filas = fn (string $reporte, ?string $periodo = null) => collect(($this->como)('contador')->getJson("/api/v1/finanzas/reportes/{$reporte}?periodo=".($periodo ?? $this->periodo))->assertOk()->json('data.filas'));
});

it('ingresos vs. gastos: cobros del mes, gastos por categoría, resultado y saldo de cada banco', function () {
    enCondominio($this->condominio, function () {
        $cuenta = CuentaBancaria::create(['banco' => 'Banco Pichincha', 'tipo' => 'corriente', 'numero' => '2100124821', 'titular' => 'Condominio', 'es_principal' => true]);
        Pago::create(['unidad_id' => $this->b->id, 'metodo' => 'transferencia', 'monto' => '80.00', 'fecha_pago' => $this->mes->day(5)->toDateString(), 'estado' => 'aprobado']);
        Pago::create(['unidad_id' => $this->a->id, 'metodo' => 'efectivo', 'monto' => '20.00', 'fecha_pago' => $this->mes->day(6)->toDateString(), 'estado' => 'aprobado']);
        Pago::create(['unidad_id' => $this->a->id, 'metodo' => 'efectivo', 'monto' => '999.00', 'fecha_pago' => $this->mes->subMonth()->day(6)->toDateString(), 'estado' => 'aprobado']); // otro mes

        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Seguridad']);
        $g = Gasto::create(['proveedor_id' => $p->id, 'numero' => '001-001-000000001', 'origen' => 'manual', 'categoria' => 'Guardianía', 'fecha_emision' => $this->mes->toDateString(), 'vence_el' => $this->mes->day(20)->toDateString(), 'subtotal' => '300.00', 'iva' => '0.00', 'total' => '300.00', 'pagado' => '300.00', 'estado' => 'pagada']);
        $pp = PagoProveedor::create(['numero' => 1, 'proveedor_id' => $p->id, 'monto' => '300.00', 'fecha_pago' => $this->mes->day(10)->toDateString(), 'referencia' => 'R1', 'estado' => 'registrado']);
        PagoProveedorAplicacion::create(['pago_proveedor_id' => $pp->id, 'gasto_id' => $g->id, 'monto' => '300.00']);
        $e = EstadoCuentaBancario::create(['cuenta_bancaria_id' => $cuenta->id, 'periodo' => $this->mes->toDateString(), 'archivo_nombre' => 'x.csv', 'saldo_inicial' => '1000.00', 'saldo_final' => '1234.50']);
        MovimientoBancario::create(['estado_cuenta_id' => $e->id, 'cuenta_bancaria_id' => $cuenta->id, 'fecha' => $this->mes->day(30)->toDateString(), 'descripcion' => 'INTERES GANADO', 'monto' => '0.37', 'huella' => str_repeat('a', 64), 'estado' => 'automatico', 'tipo' => 'interes']);
        MovimientoBancario::create(['estado_cuenta_id' => $e->id, 'cuenta_bancaria_id' => $cuenta->id, 'fecha' => $this->mes->day(21)->toDateString(), 'descripcion' => 'COMISION', 'monto' => '-0.41', 'huella' => str_repeat('b', 64), 'estado' => 'conciliado', 'tipo' => 'comision']);
    });

    $por = fn ($concepto) => ($this->filas)('ingresos_gastos')->firstWhere('concepto', $concepto)['monto'] ?? null;

    expect($por('Cuotas cobradas por transferencia'))->toBe('80.00')
        ->and($por('Cuotas cobradas en efectivo'))->toBe('20.00')
        ->and($por('Intereses bancarios'))->toBe('0.37')
        ->and($por('Total ingresos'))->toBe('100.37')
        ->and($por('Guardianía'))->toBe('300.00')
        ->and($por('Comisiones bancarias'))->toBe('0.41')
        ->and($por('Total gastos'))->toBe('300.41')
        ->and($por('Resultado del mes (ingresos − gastos)'))->toBe('-200.04')
        ->and($por('Banco Pichincha ····4821'))->toBe('1234.50');
});

it('cartera por antigüedad: por unidad y tramo', function () {
    $f = ($this->filas)('cartera_antiguedad');

    expect($f->pluck('unidad')->all())->toBe(['A-101'])
        ->and($f->first())->toMatchArray(['total' => '240.00', 'd90_mas' => '80.00', 'al_dia' => '0.00'])
        ->and(collect($f->first())->only(['d1_30', 'd31_60', 'd61_90', 'd90_mas'])->map(fn ($v) => (float) $v)->sum())->toBe(240.0);
});

it('recaudación mensual: emitido, cobrado y porcentaje entero', function () {
    $f = ($this->filas)('recaudacion_mensual');
    $fila = $f->firstWhere('emitido', '160.00');

    expect($fila)->toMatchArray(['emitido' => '160.00', 'cobrado' => '80.00', 'porcentaje' => 50]);
});

it('gastos por proveedor y estados de cuenta de todas las unidades', function () {
    enCondominio($this->condominio, function () {
        $p = Proveedor::create(['ruc' => '1790876543001', 'razon_social' => 'Ascensores']);
        Gasto::create(['proveedor_id' => $p->id, 'numero' => '001-001-000000002', 'origen' => 'manual', 'fecha_emision' => $this->mes->day(3)->toDateString(), 'vence_el' => $this->mes->day(25)->toDateString(), 'subtotal' => '500.00', 'iva' => '0.00', 'total' => '500.00', 'pagado' => '200.00', 'estado' => 'aprobada']);
    });

    expect(($this->filas)('gastos_proveedor')->first())->toMatchArray(['proveedor' => 'Ascensores', 'facturas' => 1, 'facturado' => '500.00', 'saldo_por_pagar' => '300.00']);

    $e = ($this->filas)('estados_cuenta');
    expect($e->pluck('unidad')->all())->toBe(['A-101', 'B-202'])
        ->and($e->firstWhere('unidad', 'A-101'))->toMatchArray(['saldo_pendiente' => '240.00', 'vencido' => '240.00']);
    // Sin datos personales: ni cédula, ni teléfono, ni correo
    expect(array_keys($e->first()))->toBe(['unidad', 'responsable', 'saldo_pendiente', 'vencido', 'dias_atraso', 'saldo_favor']);
});

it('el contador exporta a Excel con un código único que queda en la bitácora', function () {
    $r = ($this->como)('contador')->get("/api/v1/finanzas/reportes/cartera_antiguedad/excel?periodo={$this->periodo}")->assertOk();
    $codigo = $r->headers->get('X-Codigo-Exportacion');

    expect($codigo)->toMatch('/^EXP-[A-Z0-9]{4}$/')
        ->and($r->headers->get('Content-Disposition'))->toContain($codigo);

    $archivo = $r->baseResponse->getFile()->getPathname();
    $lector = new Reader;
    $lector->open($archivo);
    $filas = [];
    foreach ($lector->getSheetIterator() as $hoja) {
        foreach ($hoja->getRowIterator() as $fila) {
            $filas[] = $fila->toArray();
        }
    }
    $lector->close();
    expect($filas[0][0])->toBe('Jardines del Valle')
        ->and($filas[2][1])->toBe($codigo)
        ->and($filas[2][5])->toBe($this->usuarios['contador']->name)
        // El lector omite la fila en blanco que separa la cabecera del encabezado de columnas
        ->and($filas[3][0])->toBe('Unidad')
        ->and($filas[4][0])->toBe('A-101')
        ->and($filas[4][7])->toEqual(240);

    $lista = ($this->como)('admin')->getJson('/api/v1/finanzas/exportaciones')->assertOk()->json('data');
    expect($lista[0])->toMatchArray(['codigo' => $codigo, 'reporte' => 'cartera_antiguedad', 'user_nombre' => $this->usuarios['contador']->name, 'user_rol' => 'contador', 'filas' => 1]);
});

it('cada persona exporta hasta 30 archivos por día', function () {
    enCondominio($this->condominio, function () {
        foreach (range(1, ExportarReporteAction::LIMITE_DIARIO) as $i) {
            Exportacion::create(['codigo' => 'EXP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'reporte' => 'cartera_antiguedad', 'titulo' => 'x', 'formato' => 'xlsx', 'filas' => 0, 'user_id' => $this->usuarios['contador']->id, 'user_nombre' => 'Contador']);
        }
    });

    ($this->como)('contador')->getJson("/api/v1/finanzas/reportes/cartera_antiguedad/excel?periodo={$this->periodo}")->assertStatus(429)->assertJsonPath('error.code', 'LIMITE_EXPORTACIONES');
    // Otra persona no está limitada por la primera
    ($this->como)('admin')->get("/api/v1/finanzas/reportes/cartera_antiguedad/excel?periodo={$this->periodo}")->assertOk();
});

it('quién ve y quién exporta; reporte y mes inválidos', function () {
    ($this->como)('guardia')->getJson('/api/v1/finanzas/reportes/cartera_antiguedad')->assertForbidden();
    ($this->como)('guardia')->get('/api/v1/finanzas/reportes/cartera_antiguedad/excel')->assertForbidden();
    ($this->como)('tesorero')->get('/api/v1/finanzas/reportes/cartera_antiguedad/excel')->assertOk();
    ($this->como)('admin')->getJson('/api/v1/finanzas/reportes/no_existe')->assertNotFound();
    ($this->como)('admin')->getJson('/api/v1/finanzas/reportes/cartera_antiguedad?periodo=2026-13')->assertStatus(422);
});

it('cada condominio ve sus propios reportes y exportaciones', function () {
    ($this->como)('contador')->get("/api/v1/finanzas/reportes/cartera_antiguedad/excel?periodo={$this->periodo}")->assertOk();
    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();
    $api = fn () => $this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id);

    expect($api()->getJson('/api/v1/finanzas/exportaciones')->json('data'))->toBe([])
        ->and($api()->getJson('/api/v1/finanzas/reportes/cartera_antiguedad')->json('data.filas'))->toBe([]);
});
