<?php

use App\Core\Auth\Services\Totp;

// Secreto de prueba del RFC 6238: "12345678901234567890" en Base32
const SECRETO_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('coincide con los vectores de prueba del RFC 6238 (SHA-1, 6 dígitos)', function (int $instante, string $esperado) {
    expect(Totp::codigo(SECRETO_RFC, Totp::paso($instante)))->toBe($esperado);
})->with([
    'T=59' => [59, '287082'],
    'T=1111111109' => [1111111109, '081804'],
    'T=1111111111' => [1111111111, '050471'],
    'T=1234567890' => [1234567890, '005924'],
    'T=2000000000' => [2000000000, '279037'],
    'T=20000000000' => [20000000000, '353130'],
]);

it('convierte a Base32 y de vuelta sin perder bytes', function () {
    $bytes = random_bytes(20);
    $texto = Totp::base32($bytes);

    expect($texto)->toMatch('/^[A-Z2-7]{32}$/')->and(Totp::deBase32($texto))->toBe($bytes)
        ->and(Totp::base32('12345678901234567890'))->toBe(SECRETO_RFC);
});

it('acepta el paso anterior y el siguiente por desfase del reloj, no más', function () {
    $ahora = 1_800_000_000;
    $paso = Totp::paso($ahora);

    expect(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $paso), 0, 1, $ahora))->toBe($paso)
        ->and(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $paso - 1), 0, 1, $ahora))->toBe($paso - 1)
        ->and(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $paso + 1), 0, 1, $ahora))->toBe($paso + 1)
        ->and(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $paso - 2), 0, 1, $ahora))->toBeNull()
        ->and(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $paso + 2), 0, 1, $ahora))->toBeNull();
});

it('no acepta un paso ya usado', function () {
    $ahora = 1_800_000_000;
    $paso = Totp::paso($ahora);
    $codigo = Totp::codigo(SECRETO_RFC, $paso);

    expect(Totp::verificar(SECRETO_RFC, $codigo, $paso, 1, $ahora))->toBeNull()
        ->and(Totp::verificar(SECRETO_RFC, $codigo, $paso - 1, 1, $ahora))->toBe($paso);
});

it('rechaza lo que no son seis dígitos y tolera espacios', function () {
    $ahora = 1_800_000_000;
    $codigo = Totp::codigo(SECRETO_RFC, Totp::paso($ahora));

    expect(Totp::verificar(SECRETO_RFC, '', 0, 1, $ahora))->toBeNull()
        ->and(Totp::verificar(SECRETO_RFC, 'abcdef', 0, 1, $ahora))->toBeNull()
        ->and(Totp::verificar(SECRETO_RFC, $codigo.'1', 0, 1, $ahora))->toBeNull()
        ->and(Totp::verificar(SECRETO_RFC, substr($codigo, 0, 3).' '.substr($codigo, 3), 0, 1, $ahora))->not->toBeNull();
});

it('arma la dirección otpauth que lee la app autenticadora', function () {
    $uri = Totp::uri('ABCDEFGH', 'ana@ejemplo.ec', 'SAFIC');

    expect($uri)->toStartWith('otpauth://totp/SAFIC%3Aana%40ejemplo.ec?secret=ABCDEFGH&issuer=SAFIC')
        ->and($uri)->toContain('digits=6')->toContain('period=30');
});

it('genera secretos distintos de 160 bits', function () {
    expect(Totp::generarSecreto())->toMatch('/^[A-Z2-7]{32}$/')->not->toBe(Totp::generarSecreto());
});
