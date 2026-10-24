<?php

use App\Core\Permissions\Permiso;
use App\Modules\Asambleas\Http\Controllers\AsambleasController;
use App\Modules\Asambleas\Http\Controllers\AsistenciaController;
use App\Modules\Asambleas\Http\Controllers\MesaController;
use App\Modules\Asambleas\Http\Controllers\PoderesController;
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

Route::get('asambleas', [AsambleasController::class, 'index'])->middleware('permission:'.Permiso::AsambleasPreparar->value.'|'.Permiso::AsambleasRegistrar->value.'|'.Permiso::AsambleasInstalar->value);
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

// Convocar (publica el anuncio con confirmación), cancelar, seguimiento y constancia
Route::post('asambleas/{asamblea}/convocar', [AsambleasController::class, 'convocar'])->whereNumber('asamblea')->middleware($preparar);
Route::post('asambleas/{asamblea}/cancelar', [AsambleasController::class, 'cancelar'])->whereNumber('asamblea')->middleware($preparar);
Route::get('asambleas/{asamblea}/convocatoria', [AsambleasController::class, 'convocatoria'])->whereNumber('asamblea')->middleware($preparar);
Route::post('asambleas/{asamblea}/convocatoria/reenviar', [AsambleasController::class, 'reenviarConvocatoria'])->whereNumber('asamblea')->middleware($preparar);
Route::get('asambleas/{asamblea}/constancia.pdf', [AsambleasController::class, 'constancia'])->whereNumber('asamblea')->middleware($preparar);

// Poderes (Art. 40): el propietario sube el poder o la carta; la administración lo valida
$propias = 'permission:'.Permiso::AsambleasPropias->value;
$prepararOPropias = 'permission:'.Permiso::AsambleasPreparar->value.'|'.Permiso::AsambleasPropias->value;

Route::get('asambleas/{asamblea}/poderes', [PoderesController::class, 'index'])->whereNumber('asamblea')->middleware($preparar);
Route::post('asambleas/{asamblea}/poderes', [PoderesController::class, 'store'])->whereNumber('asamblea')->middleware($prepararOPropias);
Route::post('poderes/{poder}/validar', [PoderesController::class, 'validar'])->whereNumber('poder')->middleware($preparar);
Route::post('poderes/{poder}/rechazar', [PoderesController::class, 'rechazar'])->whereNumber('poder')->middleware($preparar);
Route::delete('poderes/{poder}', [PoderesController::class, 'destroy'])->whereNumber('poder')->middleware($prepararOPropias.'|'.Permiso::AsambleasRegistrar->value);

// Las asambleas del propietario (próximas, sus unidades y el poder de cada una)
Route::get('mis-asambleas', [PoderesController::class, 'mias'])->middleware($propias);

// Asistencia y quórum: el secretario abre el registro y marca en mesa; el propietario de una asamblea virtual dice «estoy presente»
$registrar = 'permission:'.Permiso::AsambleasRegistrar->value;
$registrarOPropias = 'permission:'.Permiso::AsambleasRegistrar->value.'|'.Permiso::AsambleasPropias->value;
$instalar = 'permission:'.Permiso::AsambleasInstalar->value;

Route::post('asambleas/{asamblea}/registro/abrir', [AsistenciaController::class, 'abrir'])->whereNumber('asamblea')->middleware($registrar);
Route::get('asambleas/{asamblea}/asistencia', [AsistenciaController::class, 'index'])->whereNumber('asamblea')->middleware($registrar);
Route::post('asambleas/{asamblea}/asistencia', [AsistenciaController::class, 'registrar'])->whereNumber('asamblea')->middleware($registrarOPropias);
Route::post('asambleas/{asamblea}/salida', [AsistenciaController::class, 'salida'])->whereNumber('asamblea')->middleware($registrarOPropias);
Route::get('asambleas/{asamblea}/quorum', [AsistenciaController::class, 'quorum'])->whereNumber('asamblea')->middleware($registrarOPropias);

// La mesa: el presidente (o el vicepresidente en subrogación) instala; quien registra también la ve
Route::get('asambleas/{asamblea}/mesa', [MesaController::class, 'show'])->whereNumber('asamblea')->middleware('permission:'.Permiso::AsambleasInstalar->value.'|'.Permiso::AsambleasRegistrar->value);
Route::post('asambleas/{asamblea}/instalar', [MesaController::class, 'instalar'])->whereNumber('asamblea')->middleware($instalar);
