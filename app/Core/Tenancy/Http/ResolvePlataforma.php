<?php

namespace App\Core\Tenancy\Http;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del panel de plataforma (/api/v1/plataforma/*).
 *
 * Fija el equipo 0 de spatie (donde viven super admin, soporte, cobranza…)
 * para que el middleware "permission" mire los permisos de plataforma y no los
 * de un condominio. No fija condominio: estas rutas no leen tablas con RLS, y
 * el header X-Condominio-Id se ignora.
 */
final class ResolvePlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        setPermissionsTeamId(Rol::EQUIPO_PLATAFORMA);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        if ($user->roles()->doesntExist()) {
            throw new ApiException('SIN_PERMISO', 'No tienes acceso al panel de la plataforma.', 403);
        }

        return $next($request);
    }
}
