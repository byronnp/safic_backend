<?php

use App\Core\Permissions\Permiso;
use App\Modules\Unidades\Http\Controllers\BloqueController;
use App\Modules\Unidades\Http\Controllers\UnidadController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Unidades en el condominio. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('bloques', [BloqueController::class, 'index'])
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::post('bloques', [BloqueController::class, 'store'])
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::get('unidades', [UnidadController::class, 'index'])
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::get('unidades/resumen', [UnidadController::class, 'resumen'])
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::post('unidades', [UnidadController::class, 'store'])
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::get('unidades/{unidad}', [UnidadController::class, 'show'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::patch('unidades/{unidad}', [UnidadController::class, 'update'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::delete('unidades/{unidad}', [UnidadController::class, 'destroy'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);
