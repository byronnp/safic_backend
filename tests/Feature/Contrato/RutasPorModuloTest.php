<?php

use Illuminate\Support\Facades\Route;

/*
| Convención de rutas por módulo (docs/arquitectura.md):
| - cada módulo declara sus rutas en app/Modules/<Modulo>/Routes/condominio.php
|   y/o Routes/plataforma.php; routes/api.php los carga solos;
| - toda ruta de un módulo exige un permiso y va en el grupo de su ámbito.
*/

it('los módulos solo tienen archivos de rutas por ámbito', function () {
    $archivos = array_merge(
        glob(app_path('Modules/*/routes.php')) ?: [],
        glob(app_path('Modules/*/Routes/*.php')) ?: [],
    );

    $invalidos = array_filter($archivos, fn (string $archivo) => ! preg_match('#/Routes/(condominio|plataforma)\.php$#', $archivo));

    expect(array_values($invalidos))->toBe([]);
});

it('toda ruta de un módulo exige permiso y va en el grupo de su ámbito', function () {
    $problemas = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $controlador = $route->getAction('controller');

        if (! is_string($controlador) || ! str_starts_with($controlador, 'App\\Modules\\')) {
            continue;
        }

        $middlewares = $route->gatherMiddleware();
        $esPlataforma = str_starts_with($route->uri(), 'api/v1/plataforma/');
        $grupo = $esPlataforma ? 'plataforma' : 'condominio';
        $tienePermiso = collect($middlewares)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));

        if (! in_array($grupo, $middlewares, true)) {
            $problemas[] = "{$route->uri()}: falta el middleware {$grupo}";
        }
        if (! $tienePermiso) {
            $problemas[] = "{$route->uri()}: falta el permiso";
        }
    }

    expect($problemas)->toBe([]);
});
