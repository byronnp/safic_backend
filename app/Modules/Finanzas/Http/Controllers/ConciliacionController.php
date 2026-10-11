<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\ClasificarMovimientoAction;
use App\Modules\Finanzas\Actions\DeshacerMovimientoAction;
use App\Modules\Finanzas\Actions\ImportarEstadoCuentaAction;
use App\Modules\Finanzas\Actions\ObtenerConciliacionAction;
use App\Modules\Finanzas\Actions\RegistrarAjusteConciliacionAction;
use App\Modules\Finanzas\Actions\VincularMovimientoAction;
use App\Modules\Finanzas\Models\MovimientoBancario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conciliación bancaria: importar el estado de cuenta, confirmar o clasificar sus líneas y cuadrar.
 */
class ConciliacionController
{
    public function index(Request $request, ObtenerConciliacionAction $obtener): JsonResponse
    {
        $f = $request->validate([
            'cuenta_bancaria_id' => ['sometimes', 'integer'],
            'periodo' => ['sometimes', 'string', 'date_format:Y-m'],
        ]);

        return ApiResponse::ok($obtener->estados(isset($f['cuenta_bancaria_id']) ? (int) $f['cuenta_bancaria_id'] : null, $f['periodo'] ?? null));
    }

    public function show(ObtenerConciliacionAction $obtener, int $estado): JsonResponse
    {
        return ApiResponse::ok($obtener->execute($estado));
    }

    public function importar(Request $request, ImportarEstadoCuentaAction $importar, ObtenerConciliacionAction $obtener): JsonResponse
    {
        $d = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer'],
            'periodo' => ['required', 'string', 'date_format:Y-m'],
            'archivo' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
            'saldo_inicial' => ['sometimes', 'nullable', 'decimal:0,2'],
            'saldo_final' => ['sometimes', 'nullable', 'decimal:0,2'],
        ], [
            'cuenta_bancaria_id.required' => 'Elige la cuenta del banco.',
            'periodo.required' => 'Elige el mes del estado de cuenta.',
            'archivo.required' => 'Sube el estado de cuenta del banco.',
            'archivo.extensions' => 'El archivo debe ser Excel (.xlsx) o CSV.',
            'archivo.max' => 'El archivo pesa más de 5 MB.',
            'saldo_inicial.decimal' => 'El saldo tiene hasta dos decimales.',
            'saldo_final.decimal' => 'El saldo tiene hasta dos decimales.',
        ]);

        $r = $importar->execute(
            $request->user(), (int) $d['cuenta_bancaria_id'], $d['periodo'], $request->file('archivo'),
            isset($d['saldo_inicial']) ? (string) $d['saldo_inicial'] : null, isset($d['saldo_final']) ? (string) $d['saldo_final'] : null,
        );

        return ApiResponse::created(
            $obtener->execute($r['estado']->id),
            meta: ['nuevos' => $r['nuevos'], 'duplicados' => $r['duplicados'], 'fuera_del_mes' => $r['fuera_del_mes']],
            message: "Estado de cuenta importado: {$r['nuevos']} movimientos nuevos.",
        );
    }

    public function vincular(Request $request, VincularMovimientoAction $vincular, ObtenerConciliacionAction $obtener, int $movimiento): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['pago_residente', 'pago_proveedor'])],
            'pago_id' => ['required', 'integer'],
        ], ['tipo.in' => 'Elige si es un pago de residente o a un proveedor.', 'pago_id.required' => 'Elige el pago.']);

        $m = $vincular->execute($request->user(), $movimiento, $d['tipo'], (int) $d['pago_id']);

        return ApiResponse::ok($obtener->execute($m->estado_cuenta_id), message: 'Línea conciliada.');
    }

    public function clasificar(Request $request, ClasificarMovimientoAction $clasificar, ObtenerConciliacionAction $obtener, int $movimiento): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(MovimientoBancario::TIPOS_MANUALES)],
            'guardar_regla' => ['sometimes', 'boolean'],
            'patron' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['tipo.required' => 'Elige comisión, interés u otro.', 'tipo.in' => 'Elige comisión, interés u otro.']);

        $m = $clasificar->execute($request->user(), $movimiento, $d['tipo'], (bool) ($d['guardar_regla'] ?? false), $d['patron'] ?? null);

        return ApiResponse::ok($obtener->execute($m->estado_cuenta_id), message: 'Línea clasificada.');
    }

    public function deshacer(DeshacerMovimientoAction $deshacer, ObtenerConciliacionAction $obtener, int $movimiento): JsonResponse
    {
        $m = $deshacer->execute($movimiento);

        return ApiResponse::ok($obtener->execute($m->estado_cuenta_id), message: 'Conciliación deshecha.');
    }

    public function ajuste(Request $request, RegistrarAjusteConciliacionAction $ajustar, ObtenerConciliacionAction $obtener, int $estado): JsonResponse
    {
        $d = $request->validate([
            'monto' => ['required', 'decimal:0,2', 'not_in:0,0.00', 'between:-99999999,99999999'],
            'motivo' => ['required', 'string', 'min:3', 'max:300'],
        ], [
            'monto.required' => 'Escribe el monto del ajuste.',
            'monto.decimal' => 'El monto tiene hasta dos decimales.',
            'monto.not_in' => 'El ajuste no puede ser cero.',
            'motivo.required' => 'Escribe el motivo del ajuste.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $ajustar->execute($request->user(), $estado, (string) $d['monto'], $d['motivo']);

        return ApiResponse::created($obtener->execute($estado), message: 'Ajuste registrado.');
    }
}
