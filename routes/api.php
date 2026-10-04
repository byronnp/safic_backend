<?php

use App\Core\Auth\Http\Controllers\AuthController;
use App\Core\Auth\Http\Controllers\InvitacionController;
use App\Core\Menu\Http\Controllers\MenuController;
use Illuminate\Support\Facades\Route;

/*
| API de SAFIC · prefijo /api/v1 (bootstrap/app.php)
|
| Este archivo solo declara los grupos y las rutas transversales (auth, /me).
| Las rutas de negocio viven en cada módulo, un archivo por ámbito, y se cargan
| solas (orden alfabético de módulo):
|
| - app/Modules/<Modulo>/Routes/condominio.php → auth:api + condominio (X-Condominio-Id) + permiso
| - app/Modules/<Modulo>/Routes/plataforma.php → auth:api + plataforma (equipo 0), prefijo /plataforma + permiso
| - app/Modules/<Modulo>/Routes/sesion.php     → auth:api, sin condominio (catálogos compartidos)
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

    // Primer ingreso por invitación (sin sesión: el token del correo identifica a la persona)
    Route::get('invitaciones/{token}', [InvitacionController::class, 'show'])->middleware('throttle:invitacion');
    Route::post('invitaciones/{token}/aceptar', [InvitacionController::class, 'aceptar'])->middleware('throttle:invitacion');

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Con sesión y sin condominio: catálogos compartidos
Route::middleware(['auth:api', 'throttle:api'])->group(function () use ($rutasDeModulos) {
    $rutasDeModulos('sesion');
});

// Condominio activo (X-Condominio-Id)
Route::middleware(['auth:api', 'condominio', 'throttle:api'])->group(function () use ($rutasDeModulos) {
    // Roles y permisos del usuario en el condominio activo
    Route::get('me/contexto', [AuthController::class, 'contexto']);
    // Menú del perfil en el condominio activo
    Route::get('me/menu', [MenuController::class, 'condominio']);

    $rutasDeModulos('condominio');
});

// Panel de plataforma (super admin, soporte, cobranza): equipo 0, sin X-Condominio-Id
Route::middleware(['auth:api', 'plataforma', 'throttle:api'])->prefix('plataforma')->group(function () use ($rutasDeModulos) {
    // Menú del perfil de plataforma
    Route::get('me/menu', [MenuController::class, 'plataforma']);

    $rutasDeModulos('plataforma');
});
