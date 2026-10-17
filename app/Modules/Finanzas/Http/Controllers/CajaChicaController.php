<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\GuardarCajaChicaAction;
use App\Modules\Finanzas\Actions\ObtenerCajaChicaAction;
use App\Modules\Finanzas\Actions\RegistrarGastoCajaChicaAction;
use App\Modules\Finanzas\Actions\ReponerCajaChicaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Caja chica: el fondo, los gastos menores con su respaldo y la reposición.
 */
class CajaChicaController
{
    public function show(ObtenerCajaChicaAction $obtener): JsonResponse
    {
        return ApiResponse::ok($obtener->execute());
    }

    public function update(Request $request, GuardarCajaChicaAction $guardar, ObtenerCajaChicaAction $obtener): JsonResponse
    {
        $d = $request->validate([
            'fondo' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'responsable_nombre' => ['required', 'string', 'min:3', 'max:80'],
        ], [
            'fondo.required' => 'Escribe el monto del fondo.',
            'fondo.decimal' => 'El fondo tiene hasta dos decimales.',
            'fondo.gt' => 'El fondo debe ser mayor a cero.',
            'responsable_nombre.required' => 'Escribe quién queda a cargo.',
            'responsable_nombre.min' => 'El nombre es muy corto.',
        ]);

        $guardar->execute((string) $d['fondo'], $d['responsable_nombre']);

        return ApiResponse::ok($obtener->execute(), message: 'Caja chica guardada.');
    }

    public function registrar(Request $request, RegistrarGastoCajaChicaAction $registrar, ObtenerCajaChicaAction $obtener): JsonResponse
    {
        $d = $request->validate([
            'concepto' => ['required', 'string', 'min:3', 'max:120'],
            'comercio' => ['sometimes', 'nullable', 'string', 'max:80'],
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'respaldo' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], [
            'concepto.required' => 'Escribe en qué se gastó.',
            'concepto.min' => 'El concepto es muy corto.',
            'monto.required' => 'Escribe el monto.',
            'monto.decimal' => 'El monto tiene hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'fecha.required' => 'Elige la fecha del gasto.',
            'respaldo.required' => 'La foto del respaldo es obligatoria (factura, nota de venta o recibo).',
            'respaldo.mimes' => 'El respaldo debe ser una foto o un PDF.',
            'respaldo.max' => 'El respaldo pesa más de 5 MB.',
        ]);

        $registrar->execute($request->user(), $d['concepto'], $d['comercio'] ?? null, (string) $d['monto'], $d['fecha'], $request->file('respaldo'));

        return ApiResponse::created($obtener->execute(), message: 'Gasto registrado.');
    }

    public function reponer(Request $request, ReponerCajaChicaAction $reponer, ObtenerCajaChicaAction $obtener): JsonResponse
    {
        $gasto = $reponer->execute($request->user());

        return ApiResponse::created(
            $obtener->execute(),
            meta: ['gasto_id' => $gasto->id, 'gasto_numero' => $gasto->numero],
            message: "Reposición {$gasto->numero} registrada. Sigue el flujo normal: aprobación y pago.",
        );
    }
}
