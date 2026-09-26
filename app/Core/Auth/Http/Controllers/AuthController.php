<?php

namespace App\Core\Auth\Http\Controllers;

use App\Core\Auth\Http\Requests\LoginRequest;
use App\Core\Auth\Http\Resources\UsuarioResource;
use App\Core\Auth\Services\RefreshTokenService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Autenticación con JWT:
 * - access token RS256 de 15 min (el frontend lo guarda en memoria),
 * - refresh token rotativo de 30 días (cookie HttpOnly en web; cuerpo en móvil).
 * El token no lleva condominio ni roles: el condominio va en X-Condominio-Id.
 */
class AuthController
{
    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->guard()->attempt([
            'email' => mb_strtolower($request->string('email')->toString()),
            'password' => $request->string('password')->toString(),
            'activo' => true,
        ]);

        if ($token === false) {
            throw new ApiException('CREDENCIALES_INVALIDAS', 'Correo o contraseña incorrectos.', 401);
        }

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->tokenResponse($request, $user, $token, $this->refreshTokens->issue($user, $request));
    }

    public function refresh(Request $request): JsonResponse
    {
        $plain = $this->refreshTokenFrom($request);

        if ($plain === null) {
            throw new ApiException('REFRESH_INVALIDO', 'Tu sesión expiró. Inicia sesión de nuevo.', 401);
        }

        [$user, $nuevo] = $this->refreshTokens->rotate($plain, $request);

        return $this->tokenResponse($request, $user, $this->guard()->login($user), $nuevo);
    }

    public function logout(Request $request): JsonResponse
    {
        $plain = $this->refreshTokenFrom($request);

        if ($plain !== null) {
            $this->refreshTokens->revoke($plain);
        }

        // Envía el access token actual a la lista negra (Redis) hasta que expire.
        $this->guard()->logout();

        return ApiResponse::ok(message: 'Sesión cerrada.')
            ->withCookie(Cookie::create(config('safic.auth.refresh_cookie'))->withExpires(1)->withPath(config('safic.auth.refresh_cookie_path')));
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok(new UsuarioResource($user->load('condominiosActivos')));
    }

    /**
     * Roles y permisos del usuario en el condominio del header (ruta con middleware "condominio").
     */
    public function contexto(Request $request, TenantContext $tenant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok([
            'condominio_id' => $tenant->require(),
            'roles' => $user->getRoleNames()->values(),
            'permisos' => $user->getAllPermissions()->pluck('name')->sort()->values(),
        ]);
    }

    private function tokenResponse(Request $request, User $user, string $accessToken, string $refreshToken): JsonResponse
    {
        $data = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'usuario' => (new UsuarioResource($user->load('condominiosActivos')))->resolve(),
        ];

        if ($this->isMobile($request)) {
            $data['refresh_token'] = $refreshToken;

            return ApiResponse::ok($data);
        }

        return ApiResponse::ok($data)->withCookie($this->refreshCookie($refreshToken));
    }

    private function refreshCookie(string $plain): Cookie
    {
        return Cookie::create(config('safic.auth.refresh_cookie'))
            ->withValue($plain)
            ->withExpires(now()->addDays((int) config('safic.auth.refresh_ttl_days')))
            ->withPath(config('safic.auth.refresh_cookie_path'))
            ->withSecure((bool) config('safic.auth.refresh_cookie_secure'))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    private function refreshTokenFrom(Request $request): ?string
    {
        $valor = $this->isMobile($request)
            ? $request->input('refresh_token')
            : $request->cookie(config('safic.auth.refresh_cookie'));

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function isMobile(Request $request): bool
    {
        return $request->header(config('safic.auth.mobile_header')) === config('safic.auth.mobile_value');
    }

    private function guard(): JWTGuard
    {
        $guard = Auth::guard('api');
        assert($guard instanceof JWTGuard);

        return $guard;
    }
}
