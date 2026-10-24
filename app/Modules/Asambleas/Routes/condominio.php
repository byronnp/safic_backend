<?php

use App\Core\Permissions\Permiso;
use App\Modules\Asambleas\Http\Controllers\AsambleasController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Asambleas en el condominio activo. routes/api.php las carga dentro del grupo
// auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

$preparar = 'permission:'.Permiso::AsambleasPreparar->value;

// Reglas de asamblea del condominio (asambleas virtuales, mora y quórum, voto secreto, % para reformar el reglamento)
Route::get('asambleas/configuracion', [AsambleasController::class, 'configuracion'])->middleware($preparar);
Route::put('asambleas/configuracion', [AsambleasController::class, 'guardarConfiguracion'])->middleware($preparar);

// Padrón de hoy (unidades con propietario, alícuotas y mora); la foto definitiva se congela al instalar
Route::get('asambleas/padron', [AsambleasController::class, 'padron'])->middleware($preparar);

// Cómo queda la anticipación de la convocatoria (8 días hábiles) con una fecha, sin guardar nada
Route::get('asambleas/anticipacion', [AsambleasController::class, 'anticipacion'])->middleware($preparar);

Route::get('asambleas', [AsambleasController::class, 'index'])->middleware($preparar);
Route::post('asambleas', [AsambleasController::class, 'store'])->middleware($preparar);
Route::get('asambleas/{asamblea}', [AsambleasController::class, 'show'])->whereNumber('asamblea')->middleware($preparar);
Route::patch('asambleas/{asamblea}', [AsambleasController::class, 'update'])->whereNumber('asamblea')->middleware($preparar);
Route::delete('asambleas/{asamblea}', [AsambleasController::class, 'destroy'])->whereNumber('asamblea')->middleware($preparar);

// Orden del día
Route::post('asambleas/{asamblea}/puntos', [AsambleasController::class, 'storePunto'])->whereNumber('asamblea')->middleware($preparar);
Route::patch('asambleas/{asamblea}/puntos/{punto}', [AsambleasController::class, 'updatePunto'])->whereNumber(['asamblea', 'punto'])->middleware($preparar);
Route::delete('asambleas/{asamblea}/puntos/{punto}', [AsambleasController::class, 'destroyPunto'])->whereNumber(['asamblea', 'punto'])->middleware($preparar);
Route::post('asambleas/{asamblea}/puntos/{punto}/mover', [AsambleasController::class, 'moverPunto'])->whereNumber(['asamblea', 'punto'])->middleware($preparar);
Route::post('asambleas/{asamblea}/puntos/{punto}/adjunto', [AsambleasController::class, 'adjuntar'])->whereNumber(['asamblea', 'punto'])->middleware($preparar);
Route::delete('asambleas/{asamblea}/puntos/{punto}/adjunto', [AsambleasController::class, 'quitarAdjunto'])->whereNumber(['asamblea', 'punto'])->middleware($preparar);
