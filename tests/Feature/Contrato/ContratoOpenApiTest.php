<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/*
| El contrato docs/openapi.yaml y routes/api.php deben coincidir:
| - toda ruta de /api/v1 está documentada, y toda operación documentada existe;
| - si la operación declara x-permiso, la ruta exige ese mismo permiso.
|
| El YAML se lee con un lector mínimo (sin dependencias): asume el formato del
| archivo, con rutas a 2 espacios bajo "paths:" y métodos a 4 espacios.
*/

/**
 * @return list<string>
 */
function metodosHttp(): array
{
    return ['get', 'post', 'put', 'patch', 'delete'];
}

/**
 * @return array<string, array{permiso: ?string}> "POST /bloques" => [...]
 */
function operacionesDelContrato(): array
{
    $lineas = file(base_path('docs/openapi.yaml'), FILE_IGNORE_NEW_LINES) ?: [];
    $operaciones = [];
    $enPaths = false;
    $ruta = null;
    $actual = null;

    foreach ($lineas as $linea) {
        if (preg_match('/^(\S[^:]*):/', $linea, $m)) {
            $enPaths = $m[1] === 'paths';
            $ruta = $actual = null;

            continue;
        }

        if (! $enPaths) {
            continue;
        }

        if (preg_match('#^  (/\S*):\s*$#', $linea, $m)) {
            $ruta = $m[1];
            $actual = null;
        } elseif ($ruta !== null && preg_match('/^    ([a-z]+):\s*$/', $linea, $m) && in_array($m[1], metodosHttp(), true)) {
            $actual = strtoupper($m[1]).' '.$ruta;
            $operaciones[$actual] = ['permiso' => null];
        } elseif ($actual !== null && preg_match('/^      x-permiso:\s*(\S+)\s*$/', $linea, $m)) {
            $operaciones[$actual]['permiso'] = $m[1];
        }
    }

    return $operaciones;
}

/**
 * @return array<string, LaravelRoute> "POST /bloques" => ruta
 */
function rutasDeLaApi(): array
{
    $rutas = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = '/'.substr($route->uri(), strlen('api/v1/'));

        foreach ($route->methods() as $metodo) {
            if (in_array(strtolower($metodo), metodosHttp(), true)) {
                $rutas[$metodo.' '.$path] = $route;
            }
        }
    }

    return $rutas;
}

it('lee operaciones del contrato', function () {
    expect(operacionesDelContrato())->toHaveKey('POST /auth/login');
});

it('documenta en el contrato todas las rutas de la API', function () {
    $faltan = array_diff(array_keys(rutasDeLaApi()), array_keys(operacionesDelContrato()));

    expect($faltan)->toBe([], 'Rutas sin documentar en docs/openapi.yaml: '.implode(', ', $faltan));
});

it('no documenta operaciones que no existen', function () {
    $sobran = array_diff(array_keys(operacionesDelContrato()), array_keys(rutasDeLaApi()));

    expect($sobran)->toBe([], 'Operaciones del contrato sin ruta: '.implode(', ', $sobran));
});

it('declara el mismo permiso que exige la ruta', function () {
    $rutas = rutasDeLaApi();

    foreach (operacionesDelContrato() as $operacion => ['permiso' => $permiso]) {
        $middlewares = collect(($rutas[$operacion] ?? null)?->gatherMiddleware() ?? [])
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
            ->map(fn (string $m) => substr($m, strlen('permission:')))
            ->values()
            ->all();

        expect($middlewares)->toBe($permiso === null ? [] : [$permiso], "Permiso de {$operacion}");
    }
});
