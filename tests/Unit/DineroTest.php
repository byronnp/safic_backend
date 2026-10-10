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
