<?php

use App\Core\Permissions\Permiso;
use App\Modules\Finanzas\Http\Controllers\CobroController;
use App\Modules\Finanzas\Http\Controllers\CuentaBancariaController;
use App\Modules\Finanzas\Http\Controllers\CuotaController;
use App\Modules\Finanzas\Http\Controllers\EstadoCuentaController;
use App\Modules\Finanzas\Http\Controllers\GastoController;
use App\Modules\Finanzas\Http\Controllers\MiCuentaController;
use App\Modules\Finanzas\Http\Controllers\PagoController;
use App\Modules\Finanzas\Http\Controllers\PeriodoController;
use App\Modules\Finanzas\Http\Controllers\ProveedorController;
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

Route::get('mi-cuenta/pagos/{pago}/recibo', [MiCuentaController::class, 'recibo'])
    ->whereNumber('pago')
    ->middleware('permission:'.Permiso::CuentaPropia->value);

// Pagos de los residentes: la administración los aprueba (con recibo) o los rechaza
Route::get('pagos', [PagoController::class, 'index'])
    ->middleware('permission:'.Permiso::PagosAprobar->value);

Route::post('pagos/aprobar-lote', [PagoController::class, 'lote'])
    ->middleware('permission:'.Permiso::PagosAprobar->value);

Route::get('pagos/{pago}', [PagoController::class, 'show'])
    ->whereNumber('pago')
    ->middleware('permission:'.Permiso::PagosAprobar->value);

Route::post('pagos/{pago}/aprobar', [PagoController::class, 'aprobar'])
    ->whereNumber('pago')
    ->middleware('permission:'.Permiso::PagosAprobar->value);

Route::post('pagos/{pago}/rechazar', [PagoController::class, 'rechazar'])
    ->whereNumber('pago')
    ->middleware('permission:'.Permiso::PagosAprobar->value);

Route::get('pagos/{pago}/recibo', [PagoController::class, 'recibo'])
    ->whereNumber('pago')
    ->middleware('permission:'.Permiso::FinanzasVer->value);

// Cuotas del mes de las unidades y cuotas extraordinarias
Route::get('finanzas/cuotas', [CuotaController::class, 'index'])
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::post('finanzas/cuotas-extraordinarias', [CuotaController::class, 'extraordinaria'])
    ->middleware('permission:'.Permiso::CuotasEmitir->value);

// Estado de cuenta de una unidad y pago en efectivo
Route::get('unidades/{unidad}/estado-cuenta', [EstadoCuentaController::class, 'show'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::get('unidades/{unidad}/estado-cuenta/pdf', [EstadoCuentaController::class, 'pdf'])
    ->whereNumber('unidad')
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::post('unidades/{unidad}/pagos-efectivo', [EstadoCuentaController::class, 'efectivo'])
    ->whereNumber('unidad')
    ->middleware(['permission:'.Permiso::PagosAprobar->value, 'throttle:pagos']);

// Proveedores y la cuenta donde se les paga (cambiarla pide contraseña y espera 24 h)
Route::get('proveedores', [ProveedorController::class, 'index'])
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::post('proveedores', [ProveedorController::class, 'store'])
    ->middleware('permission:'.Permiso::ProveedoresEditar->value);

Route::patch('proveedores/{proveedor}', [ProveedorController::class, 'update'])
    ->whereNumber('proveedor')
    ->middleware('permission:'.Permiso::ProveedoresEditar->value);

Route::post('proveedores/{proveedor}/cuenta-bancaria', [ProveedorController::class, 'cambiarCuenta'])
    ->whereNumber('proveedor')
    ->middleware(['permission:'.Permiso::ProveedoresEditar->value, 'throttle:pagos']);

Route::delete('proveedores/{proveedor}/cuenta-bancaria/pendiente', [ProveedorController::class, 'detenerCambio'])
    ->whereNumber('proveedor')
    ->middleware('permission:'.Permiso::ProveedoresEditar->value);

// Cuentas por pagar: facturas de proveedores
Route::get('gastos', [GastoController::class, 'index'])
    ->middleware('permission:'.Permiso::FinanzasVer->value);

Route::post('gastos', [GastoController::class, 'store'])
    ->middleware(['permission:'.Permiso::GastosRegistrar->value, 'throttle:pagos']);

Route::post('gastos/importar-xml', [GastoController::class, 'importarXml'])
    ->middleware(['permission:'.Permiso::GastosRegistrar->value, 'throttle:pagos']);
