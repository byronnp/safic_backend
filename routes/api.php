<?php

use App\Core\Auth\Http\Controllers\AuthController;
use App\Core\Menu\Http\Controllers\MenuController;
use Illuminate\Support\Facades\Route;

/*
| API de SAFIC · prefijo /api/v1 (bootstrap/app.php)
|
| - Rutas de autenticación: sin condominio.
| - Rutas de negocio: auth:api + condominio (header X-Condominio-Id) + permiso.
| - Panel de plataforma: auth:api + plataforma (equipo 0) + permiso de plataforma.
| Rutas por módulo (se cargan solas, en orden alfabético de módulo):
| - app/Modules/<Modulo>/Routes/condominio.php → grupo del condominio
| - app/Modules/<Modulo>/Routes/plataforma.php → grupo /plataforma
| Este archivo solo declara los grupos y las rutas transversales (auth, /me).
*/

/**
 * Carga el archivo de rutas de cada módulo para un ámbito.
 */
$rutasDeModulos = function (string $ambito): void {
    $archivos = glob(app_path("Modules/*/Routes/{$ambito}.php")) ?: [];
    sort($archivos);

    foreach ($archivos as $archivo) {
        require $archivo;
    }
};

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:api', 'condominio', 'throttle:api'])->group(function () use ($rutasDeModulos) {
    // Roles y permisos del usuario en el condominio activo
    Route::get('me/contexto', [AuthController::class, 'contexto']);
    // Menú del perfil en el condominio activo
    Route::get('me/menu', [MenuController::class, 'condominio']);

    $rutasDeModulos('condominio');
});

Route::middleware(['auth:api', 'plataforma', 'throttle:api'])->prefix('plataforma')->group(function () use ($rutasDeModulos) {
    // Menú del perfil de plataforma
    Route::get('me/menu', [MenuController::class, 'plataforma']);

    $rutasDeModulos('plataforma');
});
