<?php

use App\Core\Permissions\Permiso;
use App\Modules\Amenidades\Http\Controllers\AmenidadController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Amenidades en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('amenidades', [AmenidadController::class, 'index'])
    ->middleware('permission:'.Permiso::AmenidadesGestionar->value);

Route::get('amenidades/catalogo', [AmenidadController::class, 'catalogo'])
    ->middleware('permission:'.Permiso::AmenidadesGestionar->value);

Route::post('amenidades', [AmenidadController::class, 'store'])
    ->middleware('permission:'.Permiso::AmenidadesGestionar->value);

Route::patch('amenidades/{amenidad}', [AmenidadController::class, 'update'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::AmenidadesGestionar->value);
