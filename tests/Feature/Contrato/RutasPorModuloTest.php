<?php

use Illuminate\Support\Facades\Route;

/*
| Convención de rutas por módulo (docs/arquitectura.md):
| - cada módulo declara sus rutas en app/Modules/<Modulo>/Routes/{condominio,plataforma,sesion}.php
|   y routes/api.php los carga solos;
| - las rutas de condominio y de plataforma exigen un permiso y van en su grupo;
| - las de sesión solo exigen sesión (catálogos compartidos, sin datos de un condominio).
*/

it('los módulos solo tienen archivos de rutas por ámbito', function () {
    $archivos = array_merge(
        glob(app_path('Modules/*/routes.php')) ?: [],
        glob(app_path('Modules/*/Routes/*.php')) ?: [],
    );

    $invalidos = array_filter($archivos, fn (string $archivo) => ! preg_match('#/Routes/(condominio|plataforma|sesion)\.php$#', $archivo));

    expect(array_values($invalidos))->toBe([]);
});

it('cada ruta de un módulo va en su grupo y exige su permiso', function () {
    $problemas = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $controlador = $route->getAction('controller');

        if (! is_string($controlador) || ! str_starts_with($controlador, 'App\\Modules\\')) {
            continue;
        }

        $middlewares = $route->gatherMiddleware();
        $uri = $route->uri();
        $tienePermiso = collect($middlewares)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
        $esPlataforma = in_array('plataforma', $middlewares, true);
        $esCondominio = in_array('condominio', $middlewares, true);

        if (! in_array('auth:api', $middlewares, true)) {
            $problemas[] = "{$uri}: falta auth:api";
        }
        if ($esPlataforma !== str_starts_with($uri, 'api/v1/plataforma/')) {
            $problemas[] = "{$uri}: las rutas de plataforma van con prefijo /plataforma y middleware plataforma";
        }
        if (($esPlataforma || $esCondominio) && ! $tienePermiso) {
            $problemas[] = "{$uri}: falta el permiso";
        }
    }

    expect($problemas)->toBe([]);
});
