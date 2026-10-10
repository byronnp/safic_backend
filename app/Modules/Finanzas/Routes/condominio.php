<?php

use App\Core\Permissions\Permiso;
use App\Modules\Finanzas\Http\Controllers\CobroController;
use App\Modules\Finanzas\Http\Controllers\CuentaBancariaController;
use App\Modules\Finanzas\Http\Controllers\MiCuentaController;
use App\Modules\Finanzas\Http\Controllers\PeriodoController;
use Illuminate\Support\Facades\Route;

// Rutas del módulo Finanzas en el condominio. routes/api.php las carga dentro del
// grupo auth:api + condominio (header X-Condominio-Id). Cada ruta exige su permiso.

Route::get('cobro', [CobroController::class, 'show'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::put('cobro', [CobroController::class, 'update'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

// Cuotas del mes y resumen financiero
Route::get('finanzas/periodos', [PeriodoController::class, 'index'])
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::post('finanzas/periodos', [PeriodoController::class, 'store'])
    ->middleware('permission:'.Permiso::CuotasEmitir->value);

Route::get('finanzas/resumen', [PeriodoController::class, 'resumen'])
    ->middleware('permission:'.Permiso::FinanzasVer->value);

// Cuentas bancarias del condominio (adonde los residentes transfieren)
Route::get('cuentas-bancarias', [CuentaBancariaController::class, 'index'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::post('cuentas-bancarias', [CuentaBancariaController::class, 'store'])
    ->middleware('permission:'.Permiso::CondominioEditar->value);

Route::patch('cuentas-bancarias/{cuenta}', [CuentaBancariaController::class, 'update'])
    ->whereNumber('cuenta')
    ->middleware('permission:'.Permiso::CondominioEditar->value);

// Cuenta del residente: lo que debe y pago por transferencia con comprobante
Route::get('mi-cuenta', [MiCuentaController::class, 'show'])
    ->middleware('permission:'.Permiso::CuentaPropia->value);

Route::post('mi-cuenta/pagos', [MiCuentaController::class, 'pagar'])
    ->middleware(['permission:'.Permiso::CuentaPropia->value, 'throttle:pagos']);
