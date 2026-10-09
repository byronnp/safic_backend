<?php

use App\Core\Permissions\Permiso;
use App\Modules\Finanzas\Http\Controllers\CobroController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Finanzas en el condominio. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('cobro', [CobroController::class, 'show'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::put('cobro', [CobroController::class, 'update'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);
