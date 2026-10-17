<?php

use App\Core\Permissions\Permiso;
use App\Modules\Reservas\Http\Controllers\AreaController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Reservas en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('areas-comunes/areas', [AreaController::class, 'index'])
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::get('areas-comunes/areas/{amenidad}', [AreaController::class, 'show'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::put('areas-comunes/areas/{amenidad}/reglas', [AreaController::class, 'reglas'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);
