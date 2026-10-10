<?php

use App\Core\Permissions\Permiso;
use App\Modules\Usuarios\Http\Controllers\DirectivaController;
use App\Modules\Usuarios\Http\Controllers\ResidenteController;
use App\Modules\Usuarios\Http\Controllers\RolController;
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

// Directiva: cuatro cargos, una persona por cargo
Route::get('directiva', [DirectivaController::class, 'index'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::get('directiva/{cargo}/candidatos', [DirectivaController::class, 'candidatos'])
    ->whereIn('cargo', ['presidente', 'vicepresidente', 'secretario', 'tesorero'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::post('directiva/{cargo}', [DirectivaController::class, 'asignar'])
    ->whereIn('cargo', ['presidente', 'vicepresidente', 'secretario', 'tesorero'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

// Roles que el condominio puede asignar (los define la plataforma) y pedido de roles nuevos
Route::get('roles', [RolController::class, 'index'])
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);

Route::post('roles/solicitudes', [RolController::class, 'solicitar'])
    ->middleware(['throttle:solicitudes', 'permission:'.Permiso::UsuariosGestionar->value]);

// Acceso de un residente a la app (a partir de su ficha de persona)
Route::post('personas/{persona}/acceso', [ResidenteController::class, 'acceso'])
    ->whereNumber('persona')
    ->middleware('permission:'.Permiso::UsuariosGestionar->value);
