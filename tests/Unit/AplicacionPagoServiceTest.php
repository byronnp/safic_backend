<?php

use App\Modules\Finanzas\Services\AplicacionPagoService;
use App\Modules\Finanzas\Services\SaldoFavorService;

/** Cuotas de $80,00 (en centavos) con ids 1, 2, 3 y tolerancia de $0,50. */
function aplicar(int $disponible, array $saldos = [8000, 8000, 8000], int $tolerancia = 50): array
{
    $cuotas = [];
    foreach ($saldos as $i => $saldo) {
        $cuotas[] = ['id' => $i + 1, 'saldo' => $saldo];
    }

    return (new AplicacionPagoService(new SaldoFavorService))->calcular($cuotas, $disponible, $tolerancia);
}

it('paga cuotas completas de la más antigua a la más reciente', function () {
    $r = aplicar(16000);

    expect($r['aplicaciones'])->toBe([
        ['cuota_id' => 1, 'monto' => 8000, 'ajuste' => 0],
        ['cuota_id' => 2, 'monto' => 8000, 'ajuste' => 0],
    ])->and($r['sobrante'])->toBe(0);
});

it('lo que sobra de las cuotas completas queda a favor', function () {
    $r = aplicar(10000);

    expect($r['aplicaciones'])->toHaveCount(1)->and($r['sobrante'])->toBe(2000);
});

it('no paga una cuota a medias: si no alcanza, todo queda a favor', function () {
    $r = aplicar(5000);

    expect($r['aplicaciones'])->toBe([])->and($r['sobrante'])->toBe(5000);
});

it('la tolerancia bancaria da por pagada una cuota a la que le faltan centavos', function () {
    $r = aplicar(7960);

    expect($r['aplicaciones'])->toBe([['cuota_id' => 1, 'monto' => 7960, 'ajuste' => 40]])->and($r['sobrante'])->toBe(0);
});

it('la tolerancia es un límite: faltando más no se da por pagada', function () {
    expect(aplicar(7950)['aplicaciones'][0])->toMatchArray(['monto' => 7950, 'ajuste' => 50])
        ->and(aplicar(7949)['aplicaciones'])->toBe([])
        ->and(aplicar(7949)['sobrante'])->toBe(7949);
});

it('la tolerancia aplica a la última cuota alcanzada y ahí termina', function () {
    $r = aplicar(15970);

    expect($r['aplicaciones'])->toBe([
        ['cuota_id' => 1, 'monto' => 8000, 'ajuste' => 0],
        ['cuota_id' => 2, 'monto' => 7970, 'ajuste' => 30],
    ])->and($r['sobrante'])->toBe(0);
});

it('después de las cuotas completas no usa la tolerancia si falta mucho', function () {
    $r = aplicar(10000, [8000, 8000]);

    expect($r['aplicaciones'])->toHaveCount(1)->and($r['sobrante'])->toBe(2000);
});

it('sin dinero o sin cuotas no hace nada', function () {
    expect(aplicar(0)['aplicaciones'])->toBe([])
        ->and(aplicar(5000, [])['aplicaciones'])->toBe([])
        ->and(aplicar(5000, [])['sobrante'])->toBe(5000);
});

it('con tolerancia cero hay que pagar exacto', function () {
    expect(aplicar(7999, [8000], 0)['aplicaciones'])->toBe([])
        ->and(aplicar(8000, [8000], 0)['aplicaciones'])->toHaveCount(1);
});
