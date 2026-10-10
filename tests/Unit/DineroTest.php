<?php

use App\Core\Support\Dinero;

it('pasa texto decimal a centavos y de vuelta sin perder nada', function (string $texto, int $centavos) {
    expect(Dinero::centavos($texto))->toBe($centavos)->and(Dinero::texto($centavos))->toBe($texto);
})->with([
    ['0.00', 0],
    ['0.05', 5],
    ['80.00', 8000],
    ['12320.50', 1232050],
    ['-13.00', -1300],
    ['99999.99', 9999999],
]);

it('acepta montos con menos decimales y los recorta a centavos', function () {
    expect(Dinero::centavos('80'))->toBe(8000)
        ->and(Dinero::centavos('80.5'))->toBe(8050)
        ->and(Dinero::centavos('0.1'))->toBe(10)
        ->and(Dinero::centavos(null))->toBe(0)
        ->and(Dinero::centavos(''))->toBe(0);
});

it('suma sin errores de coma flotante', function () {
    expect(Dinero::texto(Dinero::suma(['0.10', '0.20', '0.30'])))->toBe('0.60')
        ->and(Dinero::suma(['80.00', '80.00', '80.00']))->toBe(24000);
});

it('reparte un total según pesos sin perder ni sobrar centavos', function () {
    $partes = Dinero::repartir(10000, ['a' => 1, 'b' => 1, 'c' => 1]);

    expect(array_sum($partes))->toBe(10000)->and($partes)->toBe(['a' => 3334, 'b' => 3333, 'c' => 3333]);
});

it('reparte en proporción a las alícuotas y siempre suma el total', function () {
    $partes = Dinero::repartir(100000, [1 => 1.5, 2 => 0.75, 3 => 2.25, 4 => 0.5]);

    expect(array_sum($partes))->toBe(100000)
        ->and($partes[3])->toBeGreaterThan($partes[1])
        ->and($partes[1])->toBeGreaterThan($partes[2]);

    foreach ([1, 7, 99, 12345, 1000003] as $total) {
        expect(array_sum(Dinero::repartir($total, [1 => 0.3333, 2 => 0.3333, 3 => 0.3334, 4 => 1.7])))->toBe($total);
    }
});

it('sin pesos o con pesos en cero no reparte nada', function () {
    expect(Dinero::repartir(500, []))->toBe([])
        ->and(Dinero::repartir(500, ['a' => 0]))->toBe(['a' => 0]);
});
