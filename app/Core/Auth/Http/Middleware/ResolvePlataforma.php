<?php

namespace App\Core\Auth\Http\Middleware;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del panel de plataforma (super admin, soporte, cobranza). Fija el "equipo"
 * de spatie en 0 (plataforma) para que `permission:plataforma.*` mire los roles de
 * plataforma y no los de un condominio. Estas rutas no llevan X-Condominio-Id.
 */
final class ResolvePlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        setPermissionsTeamId(Rol::EQUIPO_PLATAFORMA);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        if ($user->getRoleNames()->isEmpty()) {
            throw new ApiException('SIN_PERMISO', 'No tienes permiso para esta acción.', 403);
        }

        return $next($request);
    }
}
