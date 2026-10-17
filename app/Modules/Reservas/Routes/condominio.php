<?php

use App\Core\Permissions\Permiso;
use App\Modules\Reservas\Http\Controllers\AreaController;
use App\Modules\Reservas\Http\Controllers\GaritaReservasController;
use App\Modules\Reservas\Http\Controllers\MisReservasController;
use App\Modules\Reservas\Http\Controllers\SolicitudController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Reservas en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('areas-comunes/areas', [AreaController::class, 'index'])
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::get('areas-comunes/areas/{amenidad}', [AreaController::class, 'show'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::put('areas-comunes/areas/{amenidad}/reglas', [AreaController::class, 'reglas'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

// Reservas del residente: solo sobre las unidades en las que vive
Route::get('mis-reservas/areas', [MisReservasController::class, 'areas'])
    ->middleware('permission:'.Permiso::ReservasPropias->value);

Route::get('mis-reservas/areas/{amenidad}/disponibilidad', [MisReservasController::class, 'disponibilidad'])
    ->whereNumber('amenidad')
    ->middleware('permission:'.Permiso::ReservasPropias->value);

Route::get('mis-reservas', [MisReservasController::class, 'index'])
    ->middleware('permission:'.Permiso::ReservasPropias->value);

Route::post('mis-reservas', [MisReservasController::class, 'store'])
    ->middleware('permission:'.Permiso::ReservasPropias->value);

Route::post('mis-reservas/{reserva}/cancelar', [MisReservasController::class, 'cancelar'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasPropias->value);

// Solicitudes de reserva con aprobación: la administración las aprueba (se cobra) o las rechaza (con motivo)
Route::get('areas-comunes/solicitudes', [SolicitudController::class, 'index'])
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::post('areas-comunes/reservas/{reserva}/aprobar', [SolicitudController::class, 'aprobar'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::post('areas-comunes/reservas/{reserva}/rechazar', [SolicitudController::class, 'rechazar'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

// Agenda de la administración: reservas de la semana, cancelar una reserva y bloquear o desbloquear un área
Route::get('areas-comunes/agenda', [SolicitudController::class, 'agenda'])
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::post('areas-comunes/reservas/{reserva}/cancelar', [SolicitudController::class, 'cancelar'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::post('areas-comunes/bloqueos', [SolicitudController::class, 'bloquear'])
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

Route::delete('areas-comunes/bloqueos/{bloqueo}', [SolicitudController::class, 'desbloquear'])
    ->whereNumber('bloqueo')
    ->middleware('permission:'.Permiso::ReservasGestionar->value);

// Garita: las reservas de hoy pagadas o gratuitas; el guardia da el ingreso y recibe el área
Route::get('garita/reservas-hoy', [GaritaReservasController::class, 'index'])
    ->middleware('permission:'.Permiso::ReservasGarita->value);

Route::post('garita/reservas/{reserva}/ingreso', [GaritaReservasController::class, 'ingreso'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasGarita->value);

Route::post('garita/reservas/{reserva}/entrega', [GaritaReservasController::class, 'entrega'])
    ->whereNumber('reserva')
    ->middleware('permission:'.Permiso::ReservasGarita->value);
