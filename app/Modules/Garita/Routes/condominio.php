<?php

use App\Core\Permissions\Permiso;
use App\Modules\Garita\Http\Controllers\MisVisitasController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Garita en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

// Visitas del residente: solo sobre las unidades en las que vive
Route::get('mis-visitas', [MisVisitasController::class, 'index'])
    ->middleware('permission:'.Permiso::VisitasPropias->value);

Route::post('mis-visitas', [MisVisitasController::class, 'store'])
    ->middleware('permission:'.Permiso::VisitasPropias->value);

Route::post('mis-visitas/{visita}/revocar', [MisVisitasController::class, 'revocar'])
    ->whereNumber('visita')
    ->middleware('permission:'.Permiso::VisitasPropias->value);
