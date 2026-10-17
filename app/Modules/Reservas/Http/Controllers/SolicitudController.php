<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Reservas\Actions\AprobarReservaAction;
use App\Modules\Reservas\Actions\ListarSolicitudesAction;
use App\Modules\Reservas\Actions\RechazarReservaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes de reserva que esperan la respuesta de la administración.
 */
class SolicitudController
{
    public function index(ListarSolicitudesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function aprobar(Request $request, AprobarReservaAction $aprobar, int $reserva): JsonResponse
    {
        $r = $aprobar->execute($this->usuario($request), $reserva);

        return ApiResponse::ok(['id' => $r->id, 'estado' => $r->estado], message: 'Reserva aprobada. Se cobró en la cuenta de la unidad.');
    }

    public function rechazar(Request $request, RechazarReservaAction $rechazar, int $reserva): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:200']], [
            'motivo.required' => 'Escribe el motivo del rechazo.',
            'motivo.min' => 'El motivo es muy corto.',
            'motivo.max' => 'El motivo tiene máximo 200 caracteres.',
        ]);
        $r = $rechazar->execute($this->usuario($request), $reserva, trim($d['motivo']));

        return ApiResponse::ok(['id' => $r->id, 'estado' => $r->estado], message: 'Solicitud rechazada.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
