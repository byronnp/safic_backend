<?php

use App\Core\Permissions\Permiso;
use App\Modules\Unidades\Http\Controllers\BloqueController;
use App\Modules\Unidades\Http\Controllers\DirectorioGaritaController;
use App\Modules\Unidades\Http\Controllers\ImportacionUnidadesController;
use App\Modules\Unidades\Http\Controllers\MascotaController;
use App\Modules\Unidades\Http\Controllers\OcupanteController;
use App\Modules\Unidades\Http\Controllers\PersonaController;
use App\Modules\Unidades\Http\Controllers\UnidadController;
use App\Modules\Unidades\Http\Controllers\VehiculoController;
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

// Importación desde Excel (antes de unidades/{unidad}: la ruta es fija)
Route::get('unidades/importacion/plantilla', [ImportacionUnidadesController::class, 'plantilla'])
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::post('unidades/importacion', [ImportacionUnidadesController::class, 'importar'])
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

// Ocupantes de una unidad (historial) y asignación
Route::get('unidades/{unidad}/ocupantes', [OcupanteController::class, 'index'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::post('unidades/{unidad}/ocupantes', [OcupanteController::class, 'store'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::patch('ocupantes/{ocupante}/finalizar', [OcupanteController::class, 'finalizar'])
    ->whereNumber('ocupante')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

// Personas del condominio
Route::get('personas', [PersonaController::class, 'index'])
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::post('personas', [PersonaController::class, 'store'])
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::get('personas/{persona}', [PersonaController::class, 'show'])
    ->whereNumber('persona')
    ->middleware('permission:'.Permiso::UnidadesVer->value);

Route::patch('personas/{persona}', [PersonaController::class, 'update'])
    ->whereNumber('persona')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

// Vehículos y mascotas de una unidad
Route::post('unidades/{unidad}/vehiculos', [VehiculoController::class, 'store'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::patch('vehiculos/{vehiculo}', [VehiculoController::class, 'update'])
    ->whereNumber('vehiculo')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::delete('vehiculos/{vehiculo}', [VehiculoController::class, 'destroy'])
    ->whereNumber('vehiculo')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::post('unidades/{unidad}/mascotas', [MascotaController::class, 'store'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::patch('mascotas/{mascota}', [MascotaController::class, 'update'])
    ->whereNumber('mascota')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

Route::delete('mascotas/{mascota}', [MascotaController::class, 'destroy'])
    ->whereNumber('mascota')
    ->middleware('permission:'.Permiso::UnidadesEditar->value);

// Directorio de garita: nombre, unidad, teléfono y placas (teléfono completo, sin cédula ni correo)
Route::get('garita/directorio', [DirectorioGaritaController::class, 'index'])
    ->middleware(['throttle:directorio', 'permission:'.Permiso::GaritaDirectorio->value]);
