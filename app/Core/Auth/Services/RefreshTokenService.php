<?php

namespace App\Core\Auth\Services;

use App\Core\Auth\Models\RefreshToken;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refresh tokens rotativos con detección de reutilización:
 * cada uso entrega uno nuevo de la misma familia; si alguien presenta uno ya
 * usado (posible robo), se revoca toda la familia y hay que iniciar sesión.
 */
final class RefreshTokenService
{
    /**
     * @return string Token en claro (solo se muestra una vez)
     */
    public function issue(User $user, Request $request, ?string $familia = null): string
    {
        $plain = Str::random(80);

        RefreshToken::create([
            'user_id' => $user->id,
            'familia' => $familia ?? (string) Str::uuid(),
            'token_hash' => $this->hash($plain),
            'expira_en' => now()->addDays((int) config('safic.auth.refresh_ttl_days')),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return $plain;
    }

    /**
     * @return array{0: User, 1: string} Usuario y nuevo refresh token en claro
     */
    public function rotate(string $plain, Request $request): array
    {
        // La revocación por reutilización se hace fuera de la transacción para
        // que no se deshaga al lanzar la excepción.
        $resultado = DB::transaction(function () use ($plain, $request): array {
            $token = RefreshToken::query()
                ->where('token_hash', $this->hash($plain))
                ->lockForUpdate()
                ->first();

            if ($token === null || $token->expira_en->isPast()) {
                return ['invalido', null];
            }

            if ($token->usado_en !== null || $token->revocado_en !== null) {
                return ['reutilizado', $token->familia];
            }

            $user = $token->user;

            if ($user === null || ! $user->activo) {
                return ['usuario_inactivo', $token->familia];
            }

            $token->forceFill(['usado_en' => now()])->save();

            return ['ok', [$user, $this->issue($user, $request, $token->familia)]];
        });

        [$estado, $valor] = $resultado;

        if ($estado === 'ok') {
            return $valor;
        }

        if (is_string($valor)) {
            $this->revokeFamily($valor);
        }

        if ($estado === 'reutilizado') {
            throw new ApiException('REFRESH_REUTILIZADO', 'Por seguridad cerramos tu sesión. Inicia sesión de nuevo.', 401);
        }

        throw $this->invalid();
    }

    public function revoke(string $plain): void
    {
        $familia = RefreshToken::query()->where('token_hash', $this->hash($plain))->value('familia');

        if ($familia !== null) {
            $this->revokeFamily($familia);
        }
    }

    public function revokeFamily(string $familia): void
    {
        RefreshToken::query()
            ->where('familia', $familia)
            ->whereNull('revocado_en')
            ->update(['revocado_en' => now()]);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function invalid(): ApiException
    {
        return new ApiException('REFRESH_INVALIDO', 'Tu sesión expiró. Inicia sesión de nuevo.', 401);
    }
}
