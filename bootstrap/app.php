<?php

use App\Core\Http\Exceptions\ApiExceptionRenderer;
use App\Core\Http\Middleware\ForzarJson;
use App\Core\Tenancy\Http\ResolveCondominio;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForzarJson::class]);

        // API sin pantallas de login: un invitado recibe 401 en JSON, no una redirección.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'condominio' => ResolveCondominio::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        // El orden importa: autenticación → condominio (fija contexto, "team" y RLS)
        // → enlace de modelos de la ruta y permisos. Así el route model binding ya
        // consulta dentro del condominio correcto.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolveCondominio::class);
        $middleware->appendToPriorityList(ResolveCondominio::class, PermissionMiddleware::class);
        $middleware->appendToPriorityList(ResolveCondominio::class, RoleMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
