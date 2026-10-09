<?php

use App\Core\Permissions\Permiso;
use App\Modules\Usuarios\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Usuarios en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('usuarios', [UsuarioController::class, 'index'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::post('usuarios', [UsuarioController::class, 'store'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::patch('usuarios/{usuario}', [UsuarioController::class, 'update'])
    ->whereNumber('usuario')
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::post('usuarios/{usuario}/invitacion', [UsuarioController::class, 'reenviar'])
    ->whereNumber('usuario')
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);
