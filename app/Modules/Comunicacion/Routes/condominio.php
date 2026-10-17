<?php

use App\Core\Permissions\Permiso;
use App\Modules\Comunicacion\Http\Controllers\AnunciosController;
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
