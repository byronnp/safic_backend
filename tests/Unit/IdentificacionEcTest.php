<?php

use App\Core\Validation\Rules\CedulaEc;
use App\Core\Validation\Rules\RucEc;

it('valida cédulas ecuatorianas', function (string $cedula, bool $valida) {
    expect(CedulaEc::esValida($cedula))->toBe($valida);
})->with([
    ['1710034065', true],
    ['1712345675', true],
    ['0912345675', true],
    ['1712345678', false],   // dígito verificador
    ['2512345678', false],   // provincia inexistente
    ['1762345678', false],   // tercer dígito ≥ 6
    ['171234567', false],    // 9 dígitos
    ['17123456AB', false],
]);

it('valida RUC ecuatorianos', function (string $ruc, bool $valido) {
    expect(RucEc::esValido($ruc))->toBe($valido);
})->with([
    ['1710034065001', true],   // persona natural
    ['1792456781001', true],   // sociedad privada
    ['1760001550001', true],   // sociedad pública
    ['1710034065000', false],  // establecimiento 000
    ['1712345678001', false],  // cédula inválida
    ['1772456781001', false],  // tercer dígito 7
    ['179245678100', false],   // 12 dígitos
]);
