<?php

use App\Core\Permissions\Permiso;
use App\Modules\Garita\Http\Controllers\GaritaController;
use App\Modules\Garita\Http\Controllers\MisVisitasController;
use App\Modules\Garita\Http\Controllers\PaquetesController;
use App\Modules\Garita\Http\Controllers\SolicitudesIngresoController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Garita en el condominio activo. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

// Visitas del residente: solo sobre las unidades en las que vive
Route::get('mis-visitas', [MisVisitasController::class, 'index'])
    ->middleware('permission:'.Permiso::VisitasPropias->value);

Route::post('mis-visitas', [MisVisitasController::class, 'store'])
    ->middleware('permission:'.Permiso::VisitasPropias->value);

Route::post('mis-visitas/{visita}/revocar', [MisVisitasController::class, 'revocar'])
    ->whereNumber('visita')
    ->middleware('permission:'.Permiso::VisitasPropias->value);

// Garita: el guardia valida el QR y registra ingresos y salidas; la administración consulta la bitácora
Route::get('garita/panel', [GaritaController::class, 'panel'])
    ->middleware('permission:'.Permiso::GaritaRegistrar->value);

Route::post('garita/qr/validar', [GaritaController::class, 'validarQr'])
    ->middleware(['permission:'.Permiso::GaritaRegistrar->value, 'throttle:garita']);

Route::post('garita/accesos', [GaritaController::class, 'ingreso'])
    ->middleware('permission:'.Permiso::GaritaRegistrar->value);

Route::post('garita/accesos/{acceso}/salida', [GaritaController::class, 'salida'])
    ->whereNumber('acceso')
    ->middleware('permission:'.Permiso::GaritaRegistrar->value);

Route::get('garita/bitacora', [GaritaController::class, 'bitacora'])
    ->middleware('permission:'.Permiso::GaritaBitacora->value);

// Paquetería: el guardia recibe (con foto) y entrega (con firma); el residente ve los de su unidad
Route::get('garita/paquetes', [PaquetesController::class, 'index'])
    ->middleware('permission:'.Permiso::PaquetesGestionar->value);

Route::post('garita/paquetes', [PaquetesController::class, 'store'])
    ->middleware(['permission:'.Permiso::PaquetesGestionar->value, 'throttle:garita']);

Route::post('garita/paquetes/{paquete}/entregar', [PaquetesController::class, 'entregar'])
    ->whereNumber('paquete')
    ->middleware(['permission:'.Permiso::PaquetesGestionar->value, 'throttle:garita']);

Route::get('mis-paquetes', [PaquetesController::class, 'mios'])
    ->middleware('permission:'.Permiso::PaquetesPropios->value);

// «¿Lo dejo pasar?»: el guardia le pregunta al residente desde la app y el residente responde
Route::post('garita/solicitudes', [SolicitudesIngresoController::class, 'pedir'])
    ->middleware(['permission:'.Permiso::GaritaRegistrar->value, 'throttle:garita']);

Route::post('garita/solicitudes/{solicitud}/ingreso', [SolicitudesIngresoController::class, 'ingreso'])
    ->whereNumber('solicitud')
    ->middleware('permission:'.Permiso::GaritaRegistrar->value);

Route::post('garita/solicitudes/{solicitud}/cancelar', [SolicitudesIngresoController::class, 'cancelar'])
    ->whereNumber('solicitud')
    ->middleware('permission:'.Permiso::GaritaRegistrar->value);

Route::get('mis-solicitudes-ingreso', [SolicitudesIngresoController::class, 'mias'])
    ->middleware('permission:'.Permiso::VisitasPropias->value);

Route::post('mis-solicitudes-ingreso/{solicitud}/responder', [SolicitudesIngresoController::class, 'responder'])
    ->whereNumber('solicitud')
    ->middleware('permission:'.Permiso::VisitasPropias->value);

// Garita sin conexión: la copia de los QR del día y el envío de lo registrado sin red
Route::get('garita/qr/vigentes', [GaritaController::class, 'qrVigentes'])
    ->middleware(['permission:'.Permiso::GaritaRegistrar->value, 'throttle:garita']);

Route::post('garita/accesos/sincronizar', [GaritaController::class, 'sincronizar'])
    ->middleware(['permission:'.Permiso::GaritaRegistrar->value, 'throttle:garita']);
