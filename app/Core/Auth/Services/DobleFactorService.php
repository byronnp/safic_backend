<?php

namespace App\Core\Auth\Services;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verificación en dos pasos de un usuario: activarla (secreto → confirmación con un código →
 * códigos de respaldo), verificar un código (TOTP o de respaldo), desactivarla y restablecerla.
 */
final class DobleFactorService
{
    public const CODIGOS_RESPALDO = 8;

    private const EMISOR = 'SAFIC';

    /**
     * Secreto nuevo (sin activar todavía). Pedirlo otra vez reemplaza el anterior sin confirmar.
     *
     * @return array{secreto: string, uri: string}
     */
    public function preparar(User $user): array
    {
        if ($user->tieneDobleFactor()) {
            throw new ApiException('DOBLE_FACTOR_YA_ACTIVO', 'La verificación en dos pasos ya está activa.', 409);
        }

        $secreto = Totp::generarSecreto();
        $user->forceFill(['two_factor_secret' => $secreto, 'two_factor_last_step' => null])->save();

        return ['secreto' => $secreto, 'uri' => Totp::uri($secreto, $user->email, self::EMISOR)];
    }

    /**
     * Activa la verificación si el código coincide con el secreto preparado. Devuelve los códigos
     * de respaldo, que solo se ven esta vez.
     *
     * @return list<string>
     */
    public function confirmar(User $user, string $codigo): array
    {
        if ($user->tieneDobleFactor()) {
            throw new ApiException('DOBLE_FACTOR_YA_ACTIVO', 'La verificación en dos pasos ya está activa.', 409);
        }
        if ($user->two_factor_secret === null) {
            throw new ApiException('DOBLE_FACTOR_SIN_PREPARAR', 'Primero genera el código QR para configurar tu app.', 409);
        }

        $paso = Totp::verificar($user->two_factor_secret, $codigo);
        if ($paso === null) {
            throw ValidationException::withMessages(['codigo' => 'El código no es correcto. Revisa que la hora de tu teléfono sea automática.']);
        }

        $codigos = $this->generarCodigos();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $paso,
            'two_factor_recovery_codes' => $this->huellas($codigos),
        ])->save();

        return $codigos;
    }

    /** Verifica un código TOTP o uno de respaldo (que se gasta). */
    public function verificar(User $user, string $codigo): bool
    {
        if (! $user->tieneDobleFactor() || $user->two_factor_secret === null) {
            return false;
        }

        $paso = Totp::verificar($user->two_factor_secret, $codigo, (int) $user->two_factor_last_step);
        if ($paso !== null) {
            // Atómico: dos peticiones con el mismo código no pasan las dos
            $gastado = User::query()->whereKey($user->id)
                ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $paso))
                ->update(['two_factor_last_step' => $paso]);

            return $gastado === 1;
        }

        return $this->gastarCodigoRespaldo($user, $codigo);
    }

    /** @return list<string> */
    public function regenerarCodigos(User $user): array
    {
        $codigos = $this->generarCodigos();
        $user->forceFill(['two_factor_recovery_codes' => $this->huellas($codigos)])->save();

        return $codigos;
    }

    public function desactivar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** El contador no puede apagarla: una cuenta con ese perfil en cualquier condominio la necesita. */
    public function esObligatoria(User $user): bool
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', $user->getMorphClass())
            ->where('mr.model_id', $user->id)
            ->where('r.name', Rol::Contador->value)
            ->exists();
    }

    public function exigirContrasena(User $user, string $contrasena): void
    {
        if (! Hash::check($contrasena, $user->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
    }

    /** @return list<string> */
    private function generarCodigos(): array
    {
        $codigos = [];
        for ($i = 0; $i < self::CODIGOS_RESPALDO; $i++) {
            $texto = Totp::base32(random_bytes(7)); // 56 bits → 12 caracteres, se usan 10
            $codigos[] = substr($texto, 0, 5).'-'.substr($texto, 5, 5);
        }

        return $codigos;
    }

    /**
     * @param  list<string>  $codigos
     * @return list<string>
     */
    private function huellas(array $codigos): array
    {
        return array_map(fn (string $c) => $this->huella($c), $codigos);
    }

    private function huella(string $codigo): string
    {
        return hash('sha256', strtoupper(str_replace(['-', ' '], '', $codigo)));
    }

    private function gastarCodigoRespaldo(User $user, string $codigo): bool
    {
        $normalizado = strtoupper(str_replace(['-', ' '], '', $codigo));
        if (! preg_match('/^[A-Z2-7]{10}$/', $normalizado)) {
            return false;
        }

        return DB::transaction(function () use ($user, $normalizado): bool {
            $fresco = User::query()->lockForUpdate()->findOrFail($user->id);
            /** @var list<string> $huellas */
            $huellas = $fresco->two_factor_recovery_codes ?? [];
            $buscada = hash('sha256', $normalizado);

            $indice = null;
            foreach ($huellas as $i => $h) {
                if (hash_equals($h, $buscada)) {
                    $indice = $i;
                }
            }
            if ($indice === null) {
                return false;
            }

            unset($huellas[$indice]);
            $fresco->forceFill(['two_factor_recovery_codes' => array_values($huellas)])->save();
            $user->setAttribute('two_factor_recovery_codes', array_values($huellas));

            return true;
        });
    }
}
