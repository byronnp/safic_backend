<?php

/*
 * SAFIC: solo sobrescribimos algoritmo y llaves de php-open-source-saver/jwt-auth;
 * el resto (ttl, blacklist, etc.) viene del paquete y de las variables JWT_*.
 *
 * Las llaves se entregan como contenido PEM:
 *  - Local/CI: se leen de storage/jwt/*.pem (php artisan safic:jwt-keys).
 *  - Producción: JWT_PRIVATE_KEY / JWT_PUBLIC_KEY con el PEM desde Secrets Manager.
 */

$llave = static function (string $tipo): ?string {
    $valor = env('JWT_'.strtoupper($tipo).'_KEY');

    if (is_string($valor) && $valor !== '' && ! str_starts_with($valor, 'file://')) {
        return str_replace('\n', "\n", $valor);
    }

    $ruta = is_string($valor) && str_starts_with($valor, 'file://')
        ? substr($valor, 7)
        : storage_path("jwt/{$tipo}.pem");

    return is_file($ruta) ? (string) file_get_contents($ruta) : null;
};

return [
    // No se usa con RS256, pero el paquete espera un string.
    'secret' => env('JWT_SECRET') ?: (string) env('APP_KEY', ''),

    'algo' => env('JWT_ALGO', 'RS256'),

    // Minutos de vida del access token
    'ttl' => (int) env('JWT_TTL', 15),

    'keys' => [
        'public' => $llave('public'),
        'private' => $llave('private'),
        'passphrase' => env('JWT_PASSPHRASE') ?: null,
    ],
];
