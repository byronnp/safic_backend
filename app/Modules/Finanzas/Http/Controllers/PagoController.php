<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Permissions\Permiso;
use App\Models\User;
use App\Modules\Finanzas\Actions\AprobarPagoAction;
use App\Modules\Finanzas\Actions\AprobarPagosEnLoteAction;
use App\Modules\Finanzas\Actions\GenerarReciboPdfAction;
use App\Modules\Finanzas\Actions\ListarPagosAction;
use App\Modules\Finanzas\Actions\RechazarPagoAction;
use App\Modules\Finanzas\Actions\VerPagoAction;
use App\Modules\Finanzas\Models\Pago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Pagos de los residentes que revisa la administración: ver, aprobar (con recibo), rechazar.
 */
class PagoController
{
    public function index(Request $request, ListarPagosAction $listar): JsonResponse
    {
        $estado = $request->validate(['estado' => ['sometimes', Rule::in([Pago::PENDIENTE, Pago::APROBADO, Pago::RECHAZADO])]], [
            'estado.in' => 'Elige pendientes, aprobados o rechazados.',
        ])['estado'] ?? Pago::PENDIENTE;
        $r = $listar->execute($estado);

        return ApiResponse::ok($r['pagos'], ['conteos' => $r['conteos'], 'tolerancia' => $r['tolerancia']]);
    }

    public function show(VerPagoAction $ver, int $pago): JsonResponse
    {
        return ApiResponse::ok($ver->execute($pago));
    }

    public function aprobar(Request $request, AprobarPagoAction $aprobar, VerPagoAction $ver, int $pago): JsonResponse
    {
        $datos = $request->validate(['monto_recibido' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999']], [
            'monto_recibido.decimal' => 'El monto tiene hasta dos decimales.',
            'monto_recibido.gt' => 'El monto recibido debe ser mayor a cero.',
        ]);
        $aprobar->execute($pago, isset($datos['monto_recibido']) ? (string) $datos['monto_recibido'] : null, $this->actor($request));

        return ApiResponse::ok($ver->execute($pago), message: 'Pago aprobado.');
    }

    public function rechazar(Request $request, RechazarPagoAction $rechazar, VerPagoAction $ver, int $pago): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Escribe el motivo del rechazo.',
            'motivo.min' => 'El motivo es muy corto.',
            'motivo.max' => 'El motivo tiene máximo 300 caracteres.',
        ]);
        $rechazar->execute($pago, $datos['motivo'], $this->actor($request));

        return ApiResponse::ok($ver->execute($pago), message: 'Pago rechazado.');
    }

    public function lote(Request $request, AprobarPagosEnLoteAction $lote): JsonResponse
    {
        $datos = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer']], [
            'ids.required' => 'Elige al menos un pago.',
            'ids.min' => 'Elige al menos un pago.',
        ]);
        $resultados = $lote->execute(array_map('intval', $datos['ids']), $this->actor($request));
        $aprobados = count(array_filter($resultados, fn (array $r) => $r['ok']));

        return ApiResponse::ok($resultados, ['aprobados' => $aprobados, 'omitidos' => count($resultados) - $aprobados],
            message: $aprobados === 1 ? '1 pago aprobado.' : "{$aprobados} pagos aprobados.");
    }

    public function recibo(Request $request, GenerarReciboPdfAction $recibo, int $pago): Response
    {
        $r = $recibo->execute($pago, (bool) $this->actor($request)->can(Permiso::ResidentesVerDatos->value));

        return $this->pdf($r);
    }

    /**
     * @param  array{contenido: string, nombre: string}  $r
     */
    public static function respuestaPdf(array $r): Response
    {
        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array{contenido: string, nombre: string}  $r
     */
    private function pdf(array $r): Response
    {
        return self::respuestaPdf($r);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
