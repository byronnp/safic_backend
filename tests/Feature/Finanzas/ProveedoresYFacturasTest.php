<?php

use App\Core\Permissions\Rol;
use App\Core\Support\FacturaSri;
use App\Modules\Finanzas\Mail\CambioCuentaProveedorMail;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Clave de acceso de 49 dígitos con su verificador módulo 11. */
function claveSri(string $base48): string
{
    $suma = 0;
    $factor = 2;
    for ($i = 47; $i >= 0; $i--) {
        $suma += (int) $base48[$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $d = 11 - ($suma % 11);

    return $base48.($d === 11 ? 0 : ($d === 10 ? 1 : $d));
}

function xmlFactura(array $o = []): string
{
    $o += [
        'ruc' => '1790876543001', 'razon' => 'Ascensores Andinos S.A.', 'fecha' => hoyLocal()->subDays(3)->format('d/m/Y'),
        'sub' => '304.35', 'iva' => '45.65', 'total' => '350.00', 'secuencial' => '000007902', 'clave' => claveSri(str_pad('1', 48, '7')),
    ];

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<factura id="comprobante" version="1.1.0">
  <infoTributaria><razonSocial>{$o['razon']}</razonSocial><ruc>{$o['ruc']}</ruc><claveAcceso>{$o['clave']}</claveAcceso><estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$o['secuencial']}</secuencial></infoTributaria>
  <infoFactura><fechaEmision>{$o['fecha']}</fechaEmision><totalSinImpuestos>{$o['sub']}</totalSinImpuestos><totalConImpuestos><totalImpuesto><codigo>2</codigo><codigoPorcentaje>4</codigoPorcentaje><baseImponible>{$o['sub']}</baseImponible><valor>{$o['iva']}</valor></totalImpuesto></totalConImpuestos><importeTotal>{$o['total']}</importeTotal></infoFactura>
  <detalles><detalle><descripcion>Mantenimiento de ascensores</descripcion></detalle></detalles>
</factura>
XML;
}

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local', ['serve' => true]);

    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle']);
    [$this->admin] = usuarioConToken($this->condominio);
    [$contador] = usuarioConToken($this->condominio, Rol::Contador);
    $contador->forceFill(['two_factor_confirmed_at' => now()])->save(); // el contador solo trabaja con 2FA
    // Token nuevo en cada petición: algunas pruebas viajan en el tiempo y el anterior expiraría
    $como = function ($user) {
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($user->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->tesorero = fn () => $como($this->admin);
    $this->contador = fn () => $como($contador);
    $this->xml = fn (array $o = []) => UploadedFile::fake()->createWithContent('factura.xml', xmlFactura($o));
    $this->proveedor = fn (array $d = []) => enCondominio($this->condominio, fn () => Proveedor::create($d + ['ruc' => '1790876543001', 'razon_social' => 'Ascensores Andinos S.A.', 'categoria' => 'Mantenimiento']));
});

it('valida la clave de acceso con su dígito verificador', function () {
    expect(FacturaSri::claveValida(claveSri(str_pad('1', 48, '7'))))->toBeTrue()
        ->and(FacturaSri::claveValida(substr(claveSri(str_pad('1', 48, '7')), 0, 48).'0'))->not->toBeTrue()
        ->and(FacturaSri::claveValida('123'))->toBeFalse();
});

it('importa el XML: crea el proveedor por RUC y deja la factura por aprobar', function () {
    $r = ($this->tesorero)()->post('/api/v1/gastos/importar-xml', ['xml' => ($this->xml)()], ['Accept' => 'application/json'])->assertCreated();

    expect($r->json('data'))->toMatchArray([
        'numero' => '001-001-000007902', 'subtotal' => '304.35', 'iva' => '45.65', 'total' => '350.00', 'saldo' => '350.00',
        'estado' => 'por_aprobar', 'origen' => 'xml', 'tiene_xml' => true, 'descripcion' => 'Mantenimiento de ascensores',
    ])->and($r->json('data.proveedor.ruc'))->toBe('1790876543001');

    enCondominio($this->condominio, function () {
        $g = Gasto::query()->firstOrFail();
        expect(Proveedor::count())->toBe(1)
            ->and($g->registrado_por)->toBe($this->admin->id)
            ->and($g->vence_el->toDateString())->toBe($g->fecha_emision->addDays(30)->toDateString());
        Storage::disk('local')->assertExists($g->xml_ruta);
    });
});

it('no registra la misma factura dos veces', function () {
    ($this->tesorero)()->post('/api/v1/gastos/importar-xml', ['xml' => ($this->xml)()], ['Accept' => 'application/json'])->assertCreated();
    ($this->tesorero)()->post('/api/v1/gastos/importar-xml', ['xml' => ($this->xml)()], ['Accept' => 'application/json'])
        ->assertStatus(409)->assertJsonPath('error.code', 'FACTURA_DUPLICADA');

    expect(enCondominio($this->condominio, fn () => Gasto::count()))->toBe(1);
});

it('rechaza XML inválidos: otra cosa, clave mala, totales que no cuadran, entidades externas', function () {
    $malos = [
        'no xml' => 'esto no es xml',
        'nota de crédito' => '<notaCredito><infoTributaria/></notaCredito>',
        'clave' => xmlFactura(['clave' => str_repeat('1', 49)]),
        'totales' => xmlFactura(['total' => '100.00']),
        'doctype' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><factura/>',
    ];
    foreach ($malos as $nombre => $contenido) {
        ($this->tesorero)()->post('/api/v1/gastos/importar-xml', ['xml' => UploadedFile::fake()->createWithContent('f.xml', $contenido)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'XML_INVALIDO');
    }
    expect(enCondominio($this->condominio, fn () => Gasto::count()))->toBe(0);
});

it('lee la factura dentro del envoltorio de autorización del SRI', function () {
    $envuelto = '<autorizacion><estado>AUTORIZADO</estado><comprobante><![CDATA['.xmlFactura().']]></comprobante></autorizacion>';

    ($this->tesorero)()->post('/api/v1/gastos/importar-xml', ['xml' => UploadedFile::fake()->createWithContent('a.xml', $envuelto)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.total', '350.00');
});

it('registra una factura a mano con IVA 15 % redondeado al centavo', function () {
    $p = ($this->proveedor)();

    $r = ($this->tesorero)()->postJson('/api/v1/gastos', [
        'proveedor_id' => $p->id, 'numero' => '001-002-000004512', 'fecha_emision' => hoyLocal()->toDateString(),
        'vence_el' => hoyLocal()->addDays(10)->toDateString(), 'subtotal' => '2400.33', 'con_iva' => true,
    ])->assertCreated();

    expect($r->json('data'))->toMatchArray(['subtotal' => '2400.33', 'iva' => '360.05', 'total' => '2760.38', 'origen' => 'manual', 'categoria' => 'Mantenimiento']);

    ($this->tesorero)()->postJson('/api/v1/gastos', [
        'proveedor_id' => $p->id, 'numero' => '001-002-000004513', 'fecha_emision' => hoyLocal()->toDateString(), 'subtotal' => '100.00', 'con_iva' => false,
    ])->assertCreated()->assertJsonPath('data.total', '100.00');

    ($this->tesorero)()->postJson('/api/v1/gastos', [
        'proveedor_id' => $p->id, 'numero' => '001-002-000004514', 'fecha_emision' => hoyLocal()->addDay()->toDateString(), 'subtotal' => '10.00', 'con_iva' => false,
    ])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_FUTURA');
});

it('lista las cuentas por pagar con resumen y conteos', function () {
    $p = ($this->proveedor)();
    enCondominio($this->condominio, function () use ($p) {
        $base = ['proveedor_id' => $p->id, 'origen' => 'manual', 'fecha_emision' => hoyLocal()->subDays(40), 'iva' => '0.00'];
        Gasto::create($base + ['numero' => '001-001-000000001', 'vence_el' => hoyLocal()->subDays(5), 'subtotal' => '100.00', 'total' => '100.00', 'estado' => 'aprobada', 'pagado' => '40.00']);
        Gasto::create($base + ['numero' => '001-001-000000002', 'vence_el' => hoyLocal()->addDays(5), 'subtotal' => '50.00', 'total' => '50.00', 'estado' => 'por_aprobar']);
    });

    $r = ($this->contador)()->getJson('/api/v1/gastos')->assertOk();

    expect($r->json('meta.resumen'))->toMatchArray(['por_aprobar_monto' => '50.00', 'por_pagar_monto' => '60.00', 'vencido_monto' => '60.00', 'vencido_facturas' => 1])
        ->and($r->json('meta.conteos'))->toMatchArray(['todas' => 2, 'por_aprobar' => 1, 'por_pagar' => 1])
        ->and($r->json('data.0'))->toMatchArray(['numero' => '001-001-000000001', 'saldo' => '60.00', 'vencida' => true]);

    expect(($this->contador)()->getJson('/api/v1/gastos?filtro=por_aprobar')->json('data'))->toHaveCount(1);
});

it('el contador ve pero no registra ni edita', function () {
    ($this->contador)()->getJson('/api/v1/proveedores')->assertOk();
    ($this->contador)()->post('/api/v1/gastos/importar-xml', ['xml' => ($this->xml)()], ['Accept' => 'application/json'])->assertForbidden();
    ($this->contador)()->postJson('/api/v1/proveedores', ['ruc' => '1790876543001', 'razon_social' => 'X Y Z'])->assertForbidden();
});

it('crea y edita proveedores; el RUC no se repite', function () {
    $r = ($this->tesorero)()->postJson('/api/v1/proveedores', ['ruc' => '0991122334001', 'razon_social' => 'Limpieza Total', 'categoria' => 'Limpieza'])->assertCreated();
    $id = $r->json('data.id');
    expect($r->json('data'))->toMatchArray(['saldo_por_pagar' => '0.00', 'estado' => 'sin_facturas', 'cuenta' => null]);

    ($this->tesorero)()->postJson('/api/v1/proveedores', ['ruc' => '0991122334001', 'razon_social' => 'Otra'])
        ->assertStatus(409)->assertJsonPath('error.code', 'PROVEEDOR_DUPLICADO');
    ($this->tesorero)()->postJson('/api/v1/proveedores', ['ruc' => '123', 'razon_social' => 'Mala'])->assertStatus(422);

    ($this->tesorero)()->patchJson("/api/v1/proveedores/{$id}", ['telefono' => '02 245 8800'])->assertOk()->assertJsonPath('data.telefono', '02 245 8800');
    ($this->tesorero)()->patchJson("/api/v1/proveedores/{$id}", ['activo' => false])->assertOk();
    expect(($this->tesorero)()->getJson('/api/v1/proveedores')->json('data'))->toBe([]);
});

it('la primera cuenta del proveedor queda vigente al instante y pide contraseña', function () {
    $p = ($this->proveedor)();
    $cuenta = ['banco' => 'Produbanco', 'tipo' => 'corriente', 'numero' => '02005-1234-87', 'titular' => 'Ascensores Andinos S.A.'];

    ($this->tesorero)()->postJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria", $cuenta + ['password' => 'incorrecta'])
        ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'La contraseña no es correcta.');

    ($this->tesorero)()->postJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria", $cuenta + ['password' => 'password'])->assertCreated()
        ->assertJsonPath('data.cuenta.numero', '02005-1234-87')->assertJsonPath('data.cambio_de_cuenta', null);

    // El número queda cifrado en la base
    expect(str_contains((string) DB::table('proveedor_cuentas')->value('numero'), '1234'))->toBeFalse();
});

it('cambiar la cuenta espera 24 h, avisa y se puede detener; al cumplirse reemplaza a la vigente', function () {
    Mail::fake();
    $p = ($this->proveedor)();
    $nueva = ['banco' => 'Pichincha', 'tipo' => 'ahorros', 'numero' => '2200998877', 'titular' => 'Ascensores Andinos S.A.', 'password' => 'password'];
    enCondominio($this->condominio, fn () => ProveedorCuenta::create(['proveedor_id' => $p->id, 'banco' => 'Produbanco', 'tipo' => 'corriente', 'numero' => '02005-1234-87', 'titular' => 'Ascensores Andinos S.A.', 'estado' => 'activa', 'activa_en' => now()->subMonth()]));

    $r = ($this->tesorero)()->postJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria", $nueva)->assertCreated();
    expect($r->json('data.cuenta.banco'))->toBe('Produbanco')
        ->and($r->json('data.cambio_de_cuenta.banco'))->toBe('Pichincha')
        ->and($r->json('data.cambio_de_cuenta.activa_en'))->not->toBeNull();
    Mail::assertQueued(CambioCuentaProveedorMail::class);

    ($this->tesorero)()->postJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria", $nueva)->assertStatus(409)->assertJsonPath('error.code', 'CAMBIO_EN_ESPERA');
    expect(($this->tesorero)()->getJson('/api/v1/proveedores?filtro=cuenta_en_cambio')->json('data'))->toHaveCount(1);

    // Detener: sigue la cuenta anterior
    ($this->tesorero)()->deleteJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria/pendiente")->assertOk()->assertJsonPath('data.cambio_de_cuenta', null);
    ($this->tesorero)()->deleteJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria/pendiente")->assertNotFound();

    // Pedir otra vez y cumplir las 24 h
    ($this->tesorero)()->postJson("/api/v1/proveedores/{$p->id}/cuenta-bancaria", $nueva)->assertCreated();
    $this->travel(25)->hours();
    $this->artisan('finanzas:activar-cambios-de-cuenta')->assertSuccessful();

    $r = ($this->tesorero)()->getJson('/api/v1/proveedores')->assertOk();
    expect($r->json('data.0.cuenta.banco'))->toBe('Pichincha')->and($r->json('data.0.cambio_de_cuenta'))->toBeNull();
    expect(enCondominio($this->condominio, fn () => ProveedorCuenta::where('estado', 'reemplazada')->count()))->toBe(1);
});

it('aísla proveedores y facturas entre condominios', function () {
    ($this->proveedor)();
    $otro = Condominio::factory()->create();
    [, $token] = usuarioConToken($otro);
    cambiarDeUsuario();

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/proveedores')->assertOk()->assertJsonPath('data', []);
});
