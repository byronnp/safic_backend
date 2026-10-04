<?php

use App\Core\Auth\Http\Controllers\AuthController;
use App\Core\Auth\Http\Controllers\InvitacionController;
use App\Core\Menu\Http\Controllers\MenuController;
use App\Modules\Plataforma\Http\Controllers\CatalogoController;
use Illuminate\Support\Facades\Route;

/*
| API de SAFIC · prefijo /api/v1 (bootstrap/app.php)
|
| - Rutas de autenticación: sin condominio.
| - Rutas de negocio: auth:api + condominio (header X-Condominio-Id) + permiso.
| - Panel de plataforma: auth:api + plataforma (equipo 0) + permiso de plataforma.
| - Cada módulo registra sus rutas en app/Modules/<Modulo>/routes.php.
*/

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

// Catálogos compartidos que no dependen del condominio
Route::middleware(['auth:api', 'throttle:api'])->group(function () {
    Route::get('ubicaciones', [CatalogoController::class, 'ubicaciones']);
});

Route::middleware(['auth:api', 'condominio', 'throttle:api'])->group(function () {
    // Roles y permisos del usuario en el condominio activo
    Route::get('me/contexto', [AuthController::class, 'contexto']);
    // Menú del perfil en el condominio activo
    Route::get('me/menu', [MenuController::class, 'condominio']);

    require base_path('app/Modules/Unidades/routes.php');
});

// Panel de plataforma (super admin, soporte, cobranza): sin X-Condominio-Id
Route::middleware(['auth:api', 'plataforma', 'throttle:api'])->prefix('plataforma')->group(function () {
    // Menú del perfil de plataforma
    Route::get('me/menu', [MenuController::class, 'plataforma']);

    require base_path('app/Modules/Plataforma/routes.php');
});
