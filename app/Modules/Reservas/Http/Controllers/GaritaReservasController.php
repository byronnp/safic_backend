<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Reservas\Actions\RecibirAreaAction;
use App\Modules\Reservas\Actions\RegistrarIngresoReservaAction;
use App\Modules\Reservas\Actions\ReservasDeHoyAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reservas de hoy para el guardia: dar el ingreso y recibir el área.
 */
class GaritaReservasController
{
    public function index(ReservasDeHoyAction $hoy): JsonResponse
    {
        return ApiResponse::ok($hoy->execute());
    }

    public function ingreso(Request $request, RegistrarIngresoReservaAction $ingreso, int $reserva): JsonResponse
    {
        $r = $ingreso->execute($this->usuario($request), $reserva);

        return ApiResponse::ok(['id' => $r->id, 'estado' => $r->estado], message: 'Ingreso registrado.');
    }

    public function entrega(Request $request, RecibirAreaAction $recibir, int $reserva): JsonResponse
    {
        $d = $request->validate(['danos' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500']], [
            'danos.min' => 'Describe el daño con un poco más de detalle.',
            'danos.max' => 'La descripción tiene máximo 500 caracteres.',
        ]);
        $danos = isset($d['danos']) && trim($d['danos']) !== '' ? trim($d['danos']) : null;
        $r = $recibir->execute($this->usuario($request), $reserva, $danos);

        return ApiResponse::ok(['id' => $r->id, 'estado' => $r->estado], message: $danos === null ? 'Área recibida sin novedades.' : 'Área recibida. Se envió el reporte de daños a la administración.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
