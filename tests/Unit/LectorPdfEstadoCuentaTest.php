<?php

use App\Core\Banking\LectorEstadoCuenta;
use App\Core\Banking\LectorPdfEstadoCuenta;
use App\Core\Http\Exceptions\ApiException;
use Dompdf\Dompdf;

/*
| El texto de estos casos es inventado (el PDF real tiene datos personales y no se guarda) pero tiene la forma que entrega el
| extractor del Banco del Pichincha: fecha con la oficina pegada, N.DOC. pegado a la descripción y tres montos tras un tabulador.
*/

function textoPichincha(array $lineas, string $anterior = '100.00', string $actual = '170.00', string $corte = '10-04-2026'): string
{
    return "CUENTA: 2200000000\tCICLO 1\nFECHA\tOFIC.\tN.DOC.\tDESCRIPCION\tDEBITO\tCREDITO\tSALDO\n"
        .implode("\n", $lineas)
        ."\nFECHA ÚLTIMO CORTE (FACTURA) 09-03-2026\nFECHA ESTE CORTE (FACTURA) {$corte}\nSALDO ANTERIOR\t{$anterior}\nSALDO ACTUAL\t{$actual}\n";
}

const CRONOLOGICAS = [
    "10-Mar05 10000001TRANSFERENCIA INTERNET\t0.00 50.00 150.00",
    "12-Mar05 10000002**CM RET 1\t0.50 0.00 149.50",
    "30-Mar0012 10000003TRANSFERENCIA INTERNET\t0.00 20.50 170.00",
];

it('lee los movimientos, con año del corte, referencia, monto con signo y saldos', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha(CRONOLOGICAS));

    expect($r->movimientos)->toHaveCount(3)
        ->and($r->movimientos[0])->toBe(['fecha' => '2026-03-10', 'descripcion' => 'TRANSFERENCIA INTERNET', 'referencia' => '10000001', 'monto' => '50.00', 'saldo' => '150.00'])
        ->and($r->movimientos[1])->toMatchArray(['monto' => '-0.50', 'descripcion' => '**CM RET 1'])
        ->and($r->movimientos[2]['fecha'])->toBe('2026-03-30')
        ->and($r->saldoInicial)->toBe('100.00')
        ->and($r->saldoFinal)->toBe('170.00');
});

it('si el PDF viene del más reciente al más antiguo lo devuelve en orden cronológico', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha(array_reverse(CRONOLOGICAS)));

    expect(array_column($r->movimientos, 'fecha'))->toBe(['2026-03-10', '2026-03-12', '2026-03-30'])
        ->and($r->saldoInicial)->toBe('100.00')->and($r->saldoFinal)->toBe('170.00');
});

it('une un movimiento partido en varias líneas (N.DOC. cortado)', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha([
        '10-Mar05 10000001',
        '9',
        "TRANSFERENCIA INTERNET\t0.00 50.00 150.00",
        "12-Mar05 10000002**CM RET 1\t0.50 0.00 149.50",
    ], actual: '149.50'));

    expect($r->movimientos)->toHaveCount(2)
        ->and($r->movimientos[0])->toMatchArray(['referencia' => '100000019', 'descripcion' => 'TRANSFERENCIA INTERNET', 'monto' => '50.00']);
});

it('el año de un movimiento de diciembre en un corte de enero es el anterior', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha([
        "28-Dec05 10000001TRANSFERENCIA INTERNET\t0.00 50.00 150.00",
        "03-Jan05 10000002**CM RET 1\t0.50 0.00 149.50",
    ], actual: '149.50', corte: '10-01-2027'));

    expect(array_column($r->movimientos, 'fecha'))->toBe(['2026-12-28', '2027-01-03']);
});

it('acepta los meses en español', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha([
        "05-Abr05 10000001TRANSFERENCIA INTERNET\t0.00 50.00 150.00",
    ], actual: '150.00', corte: '10-04-2026'));

    expect($r->movimientos[0]['fecha'])->toBe('2026-04-05');
});

it('no importa si el saldo de una línea no cuadra', function () {
    $lineas = CRONOLOGICAS;
    $lineas[1] = "12-Mar05 10000002**CM RET 1\t0.50 0.00 148.00";

    expect(fn () => (new LectorPdfEstadoCuenta)->interpretar(textoPichincha($lineas)))
        ->toThrow(ApiException::class, 'no cuadran');
});

it('no importa si el saldo final del resumen no cuadra con los movimientos', function () {
    expect(fn () => (new LectorPdfEstadoCuenta)->interpretar(textoPichincha(CRONOLOGICAS, actual: '999.00')))
        ->toThrow(ApiException::class, 'no cuadra');
});

it('ignora las líneas sin valor y deja los saldos del resumen', function () {
    $r = (new LectorPdfEstadoCuenta)->interpretar(textoPichincha([
        "10-Mar05 10000001TRANSFERENCIA INTERNET\t0.00 50.00 150.00",
        "11-Mar05 10000009AJUSTE INFORMATIVO\t0.00 0.00 150.00",
    ], actual: '150.00'));

    expect($r->movimientos)->toHaveCount(1);
});

it('explica los PDF que no sirven: sin texto, de otro banco o sin fecha de corte', function () {
    $lector = new LectorPdfEstadoCuenta;

    expect(fn () => $lector->interpretar('   '))->toThrow(ApiException::class, 'imagen escaneada');
    expect(fn () => $lector->interpretar("Estado de cuenta\nFECHA ESTE CORTE 10-04-2026\nSin movimientos reconocibles"))->toThrow(ApiException::class, 'Banco del Pichincha');
    expect(fn () => $lector->interpretar(CRONOLOGICAS[0]))->toThrow(ApiException::class, 'fecha de corte');
});

it('un archivo .pdf que no es un PDF se rechaza con un mensaje claro', function () {
    $ruta = tempnam(sys_get_temp_dir(), 'edc').'.pdf';
    file_put_contents($ruta, 'esto no es un pdf');

    expect(fn () => (new LectorEstadoCuenta)->leer($ruta, 'pdf'))->toThrow(ApiException::class, 'No se pudo leer el PDF');
});

it('lee un PDF de verdad (generado aquí) por la extensión .pdf del lector general', function () {
    $filas = implode('', array_map(fn (string $l): string => '<div>'.htmlspecialchars(str_replace("\t", ' ', $l)).'</div>', array_merge(
        CRONOLOGICAS,
        ['FECHA ÚLTIMO CORTE (FACTURA) 09-03-2026', 'FECHA ESTE CORTE (FACTURA) 10-04-2026', 'SALDO ANTERIOR 100.00', 'SALDO ACTUAL 170.00'],
    )));
    $ruta = tempnam(sys_get_temp_dir(), 'edc').'.pdf';
    $dompdf = new Dompdf;
    $dompdf->loadHtml('<html><body style="font-family: DejaVu Sans">'.$filas.'</body></html>');
    $dompdf->render();
    file_put_contents($ruta, $dompdf->output());

    $r = (new LectorEstadoCuenta)->leer($ruta, 'PDF');

    expect($r->movimientos)->toHaveCount(3)->and($r->saldoInicial)->toBe('100.00')->and($r->saldoFinal)->toBe('170.00')
        ->and($r->movimientos[2])->toMatchArray(['fecha' => '2026-03-30', 'monto' => '20.50']);
});
