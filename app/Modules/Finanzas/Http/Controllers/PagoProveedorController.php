<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\AnularPagoProveedorAction;
use App\Modules\Finanzas\Actions\ListarPagosProveedorAction;
use App\Modules\Finanzas\Actions\RegistrarPagoProveedorAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pagos a proveedores: registrar la transferencia hecha, listarlas y anularlas.
 */
class PagoProveedorController
{
    public function index(Request $request, ListarPagosProveedorAction $listar): JsonResponse
    {
        $f = $request->validate(['proveedor_id' => ['sometimes', 'integer']]);

        return ApiResponse::ok($listar->execute(isset($f['proveedor_id']) ? (int) $f['proveedor_id'] : null));
    }

    public function store(Request $request, RegistrarPagoProveedorAction $registrar, ListarPagosProveedorAction $listar): JsonResponse
    {
        $d = $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'facturas' => ['required', 'array', 'min:1', 'max:50'],
            'facturas.*' => ['integer', 'distinct'],
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'cuenta_bancaria_id' => ['required', 'integer'],
            'fecha_pago' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[0-9A-Za-z\-\/\. ]+$/'],
            'comprobante' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], [
            'proveedor_id.required' => 'Elige el proveedor.',
            'facturas.required' => 'Elige al menos una factura.',
            'facturas.min' => 'Elige al menos una factura.',
            'monto.required' => 'Escribe el monto transferido.',
            'monto.decimal' => 'El monto tiene hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'cuenta_bancaria_id.required' => 'Elige la cuenta desde la que pagaste.',
            'fecha_pago.required' => 'Elige la fecha de la transferencia.',
            'referencia.required' => 'Escribe la referencia del banco.',
            'referencia.regex' => 'La referencia solo lleva letras, números y guiones.',
            'comprobante.mimes' => 'El comprobante debe ser una foto o un PDF.',
            'comprobante.max' => 'El comprobante pesa más de 5 MB.',
        ]);

        $pago = $registrar->execute(
            $request->user(), (int) $d['proveedor_id'], array_map('intval', $d['facturas']), (string) $d['monto'],
            (int) $d['cuenta_bancaria_id'], $d['fecha_pago'], $d['referencia'], $request->file('comprobante'),
        );

        return ApiResponse::created($listar->uno($pago->id), message: "Pago {$pago->codigo()} registrado.");
    }

    public function anular(Request $request, AnularPagoProveedorAction $anular, ListarPagosProveedorAction $listar, int $pago): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Escribe el motivo de la anulación.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $anular->execute($request->user(), $pago, $d['motivo']);

        return ApiResponse::ok($listar->uno($pago), message: 'Pago anulado.');
    }
}
