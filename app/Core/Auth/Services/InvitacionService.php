<?php

namespace App\Core\Auth\Services;

use App\Core\Auth\Models\Invitacion;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitaciones de primer ingreso: el usuario recibe un enlace con un token de un solo
 * uso, crea su contraseña y queda activo. Del token solo se guarda el hash.
 */
final class InvitacionService
{
    public const DIAS_VIGENCIA = 7;

    /**
     * Crea una invitación nueva y anula las anteriores pendientes del mismo usuario y condominio.
     *
     * @return string Token en claro (solo va en el correo)
     */
    public function crear(User $user, Condominio $condominio, ?User $creadaPor = null): string
    {
        $token = Str::random(64);

        Invitacion::query()
            ->where('user_id', $user->id)
            ->where('condominio_id', $condominio->id)
            ->whereNull('aceptada_en')
            ->update(['expira_en' => now()]);

        Invitacion::create([
            'user_id' => $user->id,
            'condominio_id' => $condominio->id,
            'creada_por' => $creadaPor?->id,
            'token_hash' => self::hash($token),
            'expira_en' => now()->addDays(self::DIAS_VIGENCIA),
        ]);

        return $token;
    }

    public function vigente(string $token): Invitacion
    {
        $invitacion = Invitacion::query()
            ->with(['user', 'condominio'])
            ->where('token_hash', self::hash($token))
            ->first();

        if ($invitacion === null || ! $invitacion->vigente()) {
            throw new ApiException('INVITACION_INVALIDA', 'El enlace de invitación no es válido o ya expiró. Pide a tu administración que te envíe otro.', 404);
        }

        return $invitacion;
    }

    /**
     * Fija la contraseña, activa el usuario y marca la invitación como usada.
     */
    public function aceptar(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password): User {
            $invitacion = $this->vigente($token);
            $invitacion = Invitacion::query()->lockForUpdate()->findOrFail($invitacion->id);

            if (! $invitacion->vigente()) {
                throw new ApiException('INVITACION_INVALIDA', 'El enlace de invitación no es válido o ya expiró. Pide a tu administración que te envíe otro.', 404);
            }

            $user = $invitacion->user;
            $user->forceFill([
                'password' => $password,
                'activo' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $invitacion->forceFill(['aceptada_en' => now()])->save();

            return $user;
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
