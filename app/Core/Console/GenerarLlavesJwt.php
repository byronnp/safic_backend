<?php

namespace App\Core\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Crea el par de llaves RSA para firmar los JWT (RS256) en storage/jwt.
 * En producción las llaves vienen de AWS Secrets Manager, no de este comando.
 */
#[AsCommand(name: 'safic:jwt-keys')]
class GenerarLlavesJwt extends Command
{
    protected $signature = 'safic:jwt-keys {--force : Reemplaza las llaves existentes (invalida todas las sesiones)}';

    protected $description = 'Genera las llaves RSA para los tokens JWT';

    public function handle(): int
    {
        $dir = storage_path('jwt');
        $privada = $dir.'/private.pem';
        $publica = $dir.'/public.pem';

        if (file_exists($privada) && ! $this->option('force')) {
            $this->components->info('Las llaves JWT ya existen.');

            return self::SUCCESS;
        }

        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }

        $llave = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($llave === false) {
            throw new RuntimeException('OpenSSL no pudo generar la llave.');
        }

        openssl_pkey_export($llave, $pemPrivada);
        $detalles = openssl_pkey_get_details($llave);

        file_put_contents($privada, $pemPrivada);
        file_put_contents($publica, $detalles['key']);
        chmod($privada, 0600);

        $this->components->info('Llaves JWT creadas en storage/jwt.');

        return self::SUCCESS;
    }
}
