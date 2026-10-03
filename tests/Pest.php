<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Tests\TestCase;

// TestCase ya usa RefreshDatabase (ver su comentario).
pest()->extend(TestCase::class)->in('Feature');

/**
 * Crea un usuario miembro de $condominio con $rol y devuelve [usuario, access token].
 *
 * @return array{0: User, 1: string}
 */
function usuarioConToken(Condominio $condominio, ?Rol $rol = Rol::Administrador): array
{
    $user = User::factory()->miembroDe($condominio, $rol)->create();
    $token = auth('api')->tokenById($user->id);

    return [$user, $token];
}

/**
 * Ejecuta $callback dentro de un condominio (contexto + RLS).
 */
function enCondominio(Condominio $condominio, callable $callback): mixed
{
    return app(TenantContext::class)->run($condominio->id, $callback);
}
