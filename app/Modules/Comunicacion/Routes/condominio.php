<?php

use App\Core\Permissions\Permiso;
use App\Modules\Comunicacion\Http\Controllers\AnunciosController;
use App\Modules\Comunicacion\Http\Controllers\IncidenciasController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Comunicación en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

// La administración publica anuncios y sigue su lectura
Route::get('comunicacion/anuncios', [AnunciosController::class, 'index'])
    ->middleware('permission:'.Permiso::AnunciosPublicar->value);

Route::get('comunicacion/anuncios/alcance', [AnunciosController::class, 'alcance'])
    ->middleware('permission:'.Permiso::AnunciosPublicar->value);

Route::post('comunicacion/anuncios', [AnunciosController::class, 'store'])
    ->middleware(['permission:'.Permiso::AnunciosPublicar->value, 'throttle:pagos']);

Route::post('comunicacion/anuncios/{anuncio}/reenviar', [AnunciosController::class, 'reenviar'])
    ->whereNumber('anuncio')
    ->middleware(['permission:'.Permiso::AnunciosPublicar->value, 'throttle:pagos']);

Route::get('comunicacion/anuncios/{anuncio}/constancia', [AnunciosController::class, 'constancia'])
    ->whereNumber('anuncio')
    ->middleware('permission:'.Permiso::AnunciosPublicar->value);

// El residente lee y confirma los avisos que llegaron a su unidad
Route::get('mis-avisos', [AnunciosController::class, 'mios'])
    ->middleware('permission:'.Permiso::AvisosPropios->value);

Route::post('mis-avisos/{anuncio}/leer', [AnunciosController::class, 'leer'])
    ->whereNumber('anuncio')
    ->middleware('permission:'.Permiso::AvisosPropios->value);

Route::post('mis-avisos/{anuncio}/confirmar', [AnunciosController::class, 'confirmar'])
    ->whereNumber('anuncio')
    ->middleware('permission:'.Permiso::AvisosPropios->value);

// Incidencias: el residente (o el guardia) reporta y responde; la administración las gestiona por estados
Route::get('mis-incidencias', [IncidenciasController::class, 'mias'])
    ->middleware('permission:'.Permiso::IncidenciasReportar->value);

Route::post('mis-incidencias', [IncidenciasController::class, 'reportar'])
    ->middleware(['permission:'.Permiso::IncidenciasReportar->value, 'throttle:pagos']);

Route::post('mis-incidencias/{incidencia}/comentarios', [IncidenciasController::class, 'comentarResidente'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasReportar->value);

Route::post('mis-incidencias/{incidencia}/reabrir', [IncidenciasController::class, 'reabrir'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasReportar->value);

Route::post('mis-incidencias/{incidencia}/confirmar', [IncidenciasController::class, 'confirmar'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasReportar->value);

Route::get('comunicacion/incidencias', [IncidenciasController::class, 'tablero'])
    ->middleware('permission:'.Permiso::IncidenciasGestionar->value);

Route::get('comunicacion/incidencias/{incidencia}', [IncidenciasController::class, 'detalle'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasGestionar->value);

Route::post('comunicacion/incidencias/{incidencia}/comentarios', [IncidenciasController::class, 'comentar'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasGestionar->value);

Route::post('comunicacion/incidencias/{incidencia}/estado', [IncidenciasController::class, 'estado'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasGestionar->value);

Route::patch('comunicacion/incidencias/{incidencia}', [IncidenciasController::class, 'ajustar'])
    ->whereNumber('incidencia')
    ->middleware('permission:'.Permiso::IncidenciasGestionar->value);
