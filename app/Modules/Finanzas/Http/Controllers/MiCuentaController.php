<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Finanzas\Actions\EnviarPagoAction;
use App\Modules\Finanzas\Actions\GenerarReciboPdfAction;
use App\Modules\Finanzas\Actions\ObtenerMiCuentaAction;
use App\Modules\Finanzas\Http\Requests\EnviarPagoRequest;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cuenta del residente: lo que debe y el pago por transferencia con comprobante.
 */
class MiCuentaController
{
    public function show(Request $request, ObtenerMiCuentaAction $obtener): JsonResponse
    {
        return ApiResponse::ok($obtener->execute($this->usuario($request)));
    }

    public function pagar(EnviarPagoRequest $request, EnviarPagoAction $enviar, ObtenerMiCuentaAction $obtener): JsonResponse
    {
        /** @var list<int> $cuotas */
        $cuotas = array_map('intval', $request->input('cuotas', []));
        $pago = $enviar->execute(
            $this->usuario($request),
            (int) $request->input('unidad_id'),
            $cuotas,
            (string) $request->input('numero_comprobante'),
            $request->file('comprobante'),
        );

        return ApiResponse::created(
            ['pago_id' => $pago->id, 'monto' => (string) $pago->monto, 'estado' => $pago->estado, 'cuenta' => $obtener->execute($this->usuario($request))],
            message: 'Pago en revisión. Te avisaremos cuando se apruebe.',
        );
    }

    /** Recibo en PDF de un pago aprobado de mis unidades. */
    public function recibo(Request $request, GenerarReciboPdfAction $recibo, UnidadesDeCuentaAction $unidades, int $pago): Response
    {
        $usuario = $this->usuario($request);
        $ids = array_column($unidades->execute($usuario->id), 'unidad_id');

        // Es su propio recibo: la cédula completa
        return PagoController::respuestaPdf($recibo->execute($pago, true, $ids));
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
