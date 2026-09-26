<?php

use App\Core\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| API de SAFIC · prefijo /api/v1 (bootstrap/app.php)
|
| - Rutas de autenticación: sin condominio.
| - Rutas de negocio: auth:api + condominio (header X-Condominio-Id) + permiso.
| - Cada módulo registra sus rutas en app/Modules/<Modulo>/routes.php.
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:api', 'condominio', 'throttle:api'])->group(function () {
    // Roles y permisos del usuario en el condominio activo (para armar el menú)
    Route::get('me/contexto', [AuthController::class, 'contexto']);

    require base_path('app/Modules/Unidades/routes.php');
});
