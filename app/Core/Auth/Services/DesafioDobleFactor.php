<?php

namespace App\Core\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Paso intermedio del login con verificación en dos pasos: la contraseña ya fue correcta, falta
 * el código. El desafío es un token aleatorio de un solo propósito (no es una sesión), vale
 * 5 minutos y se invalida a los 5 intentos fallidos.
 */
final class DesafioDobleFactor
{
    public const MINUTOS = 5;

    public const INTENTOS = 5;

    public function crear(User $user): string
    {
        $token = Str::random(64);
        Cache::put($this->clave($token), ['user_id' => $user->id, 'intentos' => 0], now()->addMinutes(self::MINUTOS));

        return $token;
    }

    public function usuario(string $token): ?User
    {
        $datos = Cache::get($this->clave($token));

        return is_array($datos) ? User::query()->where('activo', true)->find($datos['user_id']) : null;
    }

    /** Suma un intento fallido; al llegar al máximo el desafío muere y hay que iniciar sesión otra vez. */
    public function fallo(string $token): void
    {
        $datos = Cache::get($this->clave($token));
        if (! is_array($datos)) {
            return;
        }

        $datos['intentos']++;
        if ($datos['intentos'] >= self::INTENTOS) {
            $this->olvidar($token);

            return;
        }

        Cache::put($this->clave($token), $datos, now()->addMinutes(self::MINUTOS));
    }

    public function olvidar(string $token): void
    {
        Cache::forget($this->clave($token));
    }

    private function clave(string $token): string
    {
        // Se guarda la huella: quien lea la caché no obtiene un desafío usable
        return 'doble-factor:desafio:'.hash('sha256', $token);
    }
}
