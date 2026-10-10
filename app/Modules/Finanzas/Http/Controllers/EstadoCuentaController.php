<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Finanzas\Actions\GenerarEstadoCuentaPdfAction;
use App\Modules\Finanzas\Actions\ListarPagosAction;
use App\Modules\Finanzas\Actions\ObtenerEstadoCuentaAction;
use App\Modules\Finanzas\Actions\RegistrarPagoEfectivoAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Estado de cuenta de una unidad y pago en efectivo que registra la administración.
 */
class EstadoCuentaController
{
    public function show(Request $request, ObtenerEstadoCuentaAction $estado, int $unidad): JsonResponse
    {
        [$desde, $hasta] = $this->rango($request);

        return ApiResponse::ok($estado->execute($unidad, $desde, $hasta));
    }

    public function pdf(Request $request, GenerarEstadoCuentaPdfAction $pdf, int $unidad): Response
    {
        [$desde, $hasta] = $this->rango($request);
        $r = $pdf->execute($unidad, $desde, $hasta);

        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function efectivo(Request $request, RegistrarPagoEfectivoAction $registrar, int $unidad): JsonResponse
    {
        $datos = $request->validate([
            'monto' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'fecha' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ], [
            'monto.required' => 'Escribe el monto recibido.',
            'monto.decimal' => 'El monto tiene hasta dos decimales.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'fecha.date_format' => 'La fecha no es válida.',
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $pago = $registrar->execute($unidad, (string) $datos['monto'], isset($datos['fecha']) ? (string) $datos['fecha'] : null, $actor);

        return ApiResponse::created(
            ['pago_id' => $pago->id, 'monto' => (string) $pago->monto, 'recibo' => ListarPagosAction::numeroRecibo((int) $pago->recibo_numero)],
            message: 'Pago en efectivo registrado.',
        );
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function rango(Request $request): array
    {
        $f = $request->validate([
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        return [isset($f['desde']) ? (string) $f['desde'] : null, isset($f['hasta']) ? (string) $f['hasta'] : null];
    }
}
