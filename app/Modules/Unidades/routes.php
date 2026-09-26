<?php

use App\Core\Permissions\Permiso;
use App\Modules\Unidades\Http\Controllers\BloqueController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Unidades. Se cargan dentro del grupo auth:api + condominio.

Route::get('bloques', [BloqueController::class, 'index'])
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::post('bloques', [BloqueController::class, 'store'])
    ->middleware('permission:'.Permiso::UnidadesEditar->value);
