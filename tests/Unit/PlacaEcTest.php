<?php

use App\Core\Validation\Rules\PlacaEc;

it('normaliza placas de auto y moto', function (string $entrada, string $esperada) {
    expect(PlacaEc::normalizar($entrada))->toBe($esperada);
})->with([
    'auto' => ['pba1234', 'PBA-1234'],
    'auto con guion' => ['PBA-1234', 'PBA-1234'],
    'auto formato anterior' => ['abc 123', 'ABC-123'],
    'moto' => ['ia123b', 'IA-123B'],
]);

it('rechaza lo que no es una placa', function (string $entrada) {
    expect(PlacaEc::normalizar($entrada))->toBeNull();
})->with(['PB-1234', 'PBAA-1234', '1234-PBA', 'PBA-12', '']);
