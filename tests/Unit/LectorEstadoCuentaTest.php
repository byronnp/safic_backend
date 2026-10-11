<?php

use App\Core\Banking\LectorEstadoCuenta;
use App\Core\Http\Exceptions\ApiException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function archivoTemporal(string $contenido, string $extension): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'edc').'.'.$extension;
    file_put_contents($ruta, $contenido);

    return $ruta;
}

it('lee un CSV con débito y crédito separados y saldos', function () {
    $csv = "Banco Pichincha;Cuenta 4821\nFecha;Descripción;Documento;Débito;Crédito;Saldo\n"
        ."01/09/2026;TRANSF RECIBIDA JV-A201;118801245;;80,00;15.859,63\n"
        ."05/09/2026;PAGO SERV SEGURIDAD;004430112;3.450,00;;12.409,63\n"
        ."Total;;;3.450,00;80,00;\n";

    $r = (new LectorEstadoCuenta)->leer(archivoTemporal($csv, 'csv'), 'csv');

    expect($r->movimientos)->toHaveCount(2)
        ->and($r->movimientos[0])->toMatchArray(['fecha' => '2026-09-01', 'referencia' => '118801245', 'monto' => '80.00', 'saldo' => '15859.63'])
        ->and($r->movimientos[1])->toMatchArray(['fecha' => '2026-09-05', 'monto' => '-3450.00'])
        ->and($r->saldoInicial)->toBe('15779.63')
        ->and($r->saldoFinal)->toBe('12409.63');
});

it('lee un CSV con una sola columna de monto con signo, separado por comas', function () {
    $csv = "Fecha,Detalle,Referencia,Monto,Saldo\n2026-09-30,INTERES GANADO,930,0.37,\"1,000.37\"\n2026-09-30,COMISION,931,-0.41,\"999.96\"\n";

    $r = (new LectorEstadoCuenta)->leer(archivoTemporal($csv, 'csv'), 'csv');

    expect(array_column($r->movimientos, 'monto'))->toBe(['0.37', '-0.41'])
        ->and($r->saldoInicial)->toBe('1000.00')
        ->and($r->saldoFinal)->toBe('999.96');
});

it('lee un Excel .xlsx', function () {
    $ruta = sys_get_temp_dir().'/edc'.uniqid().'.xlsx';
    $w = new Writer;
    $w->openToFile($ruta);
    $w->addRow(Row::fromValues(['Fecha', 'Descripción', 'Referencia', 'Débito', 'Crédito', 'Saldo']));
    $w->addRow(Row::fromValues(['02/09/2026', 'TRANSF RECIBIDA', '118801245', '', 80, 180]));
    $w->close();

    $r = (new LectorEstadoCuenta)->leer($ruta, 'xlsx');

    expect($r->movimientos)->toHaveCount(1)
        ->and($r->movimientos[0])->toMatchArray(['fecha' => '2026-09-02', 'monto' => '80.00'])
        ->and($r->saldoInicial)->toBe('100.00');
});

it('rechaza lo que no reconoce', function () {
    $lector = new LectorEstadoCuenta;

    expect(fn () => $lector->leer(archivoTemporal("hola\nmundo\n", 'csv'), 'csv'))->toThrow(ApiException::class, 'No reconocimos las columnas')
        ->and(fn () => $lector->leer(archivoTemporal('x', 'pdf'), 'pdf'))->toThrow(ApiException::class, 'Excel');
});
