<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\CatalogosSeeder;
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

/**
 * Catálogos de plataforma (planes, amenidades, provincias/cantones/parroquias).
 * Solo en las pruebas que los usan: la división territorial tiene ~1.600 filas.
 */
function sembrarCatalogos(): void
{
    test()->seed(CatalogosSeeder::class);
}

/**
 * Para hacer la siguiente petición con otro token en la misma prueba: el guard y
 * el singleton de JWT guardan el usuario y el token de la petición anterior.
 */
function cambiarDeUsuario(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
}
