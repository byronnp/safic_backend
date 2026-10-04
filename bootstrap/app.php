<?php

use App\Core\Auth\Http\Middleware\ResolvePlataforma;
use App\Core\Http\Exceptions\ApiExceptionRenderer;
use App\Core\Http\Middleware\ForzarJson;
use App\Core\Tenancy\Http\ResolveCondominio;
use App\Core\Tenancy\Http\ResolvePlataforma;
use App\Modules\Plataforma\Console\ImportarDpa;
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
    ->withCommands([ImportarDpa::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForzarJson::class]);

        // API sin pantallas de login: un invitado recibe 401 en JSON, no una redirección.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'condominio' => ResolveCondominio::class,
            'plataforma' => ResolvePlataforma::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        // El orden importa: autenticación → condominio (fija contexto, "team" y RLS)
        // → enlace de modelos de la ruta y permisos. Así el route model binding ya
        // consulta dentro del condominio correcto.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolveCondominio::class);
        $middleware->appendToPriorityList(ResolveCondominio::class, PermissionMiddleware::class);
        $middleware->appendToPriorityList(ResolveCondominio::class, RoleMiddleware::class);
        // Panel de plataforma: fija el equipo 0 antes de revisar permisos.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, ResolvePlataforma::class);
        $middleware->appendToPriorityList(ResolvePlataforma::class, PermissionMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
