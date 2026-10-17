<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Reservas\Actions\CancelarMiReservaAction;
use App\Modules\Reservas\Actions\DisponibilidadAreaAction;
use App\Modules\Reservas\Actions\ListarAreasReservablesAction;
use App\Modules\Reservas\Actions\ListarMisReservasAction;
use App\Modules\Reservas\Actions\ReservarAreaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reservas del residente: qué puede reservar, cuándo hay lugar, reservar, ver y cancelar las suyas.
 */
class MisReservasController
{
    public function areas(Request $request, ListarAreasReservablesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function disponibilidad(Request $request, DisponibilidadAreaAction $disponibilidad, Calendario $calendario, int $amenidad): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'dias' => ['sometimes', 'integer', 'min:1', 'max:'.DisponibilidadAreaAction::MAX_DIAS],
        ], ['desde.date_format' => 'La fecha no es válida.']);

        return ApiResponse::ok($disponibilidad->execute($amenidad, $d['desde'] ?? $calendario->hoy(), (int) ($d['dias'] ?? 7)));
    }

    public function index(Request $request, ListarMisReservasAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function store(Request $request, ReservarAreaAction $reservar, ListarMisReservasAction $listar): JsonResponse
    {
        $d = $request->validate([
            'amenidad_id' => ['required', 'integer'],
            'unidad_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'inicio' => ['required', 'date_format:H:i'],
            'horas' => ['required', 'integer', 'min:1', 'max:24'],
            'acepta_reglamento' => ['required', 'boolean'],
        ], [
            'amenidad_id.required' => 'Elige el área.',
            'unidad_id.required' => 'Elige la unidad.',
            'fecha.required' => 'Elige la fecha.',
            'inicio.required' => 'Elige el horario.',
            'horas.required' => 'Indica la duración.',
        ]);

        $reserva = $reservar->execute($this->usuario($request), (int) $d['amenidad_id'], (int) $d['unidad_id'], $d['fecha'], $d['inicio'], (int) $d['horas'], (bool) $d['acepta_reglamento']);

        return ApiResponse::created(
            collect($listar->execute($this->usuario($request)))->firstWhere('id', $reserva->id),
            message: $reserva->estado === 'pendiente' ? 'Solicitud enviada. La administración la revisará.' : 'Reserva confirmada.',
        );
    }

    public function cancelar(Request $request, CancelarMiReservaAction $cancelar, int $reserva): JsonResponse
    {
        $d = $request->validate(['motivo' => ['sometimes', 'nullable', 'string', 'max:200']]);
        $cancelar->execute($this->usuario($request), $reserva, $d['motivo'] ?? null);

        return ApiResponse::ok(null, message: 'Reserva cancelada.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
