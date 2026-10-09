<?php

use App\Core\Permissions\Permiso;
use App\Modules\Plataforma\Http\Controllers\DatosCondominioController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Plataforma en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('condominio', [DatosCondominioController::class, 'show'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::patch('condominio', [DatosCondominioController::class, 'update'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::post('condominio/logo/{variante}', [DatosCondominioController::class, 'subirLogo'])
    ->whereIn('variante', ['claro', 'oscuro'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::delete('condominio/logo/{variante}', [DatosCondominioController::class, 'quitarLogo'])
    ->whereIn('variante', ['claro', 'oscuro'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);
