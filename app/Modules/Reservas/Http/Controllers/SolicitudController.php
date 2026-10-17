<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Reservas\Actions\AprobarReservaAction;
use App\Modules\Reservas\Actions\CancelarReservaAdminAction;
use App\Modules\Reservas\Actions\ConsultarAgendaAction;
use App\Modules\Reservas\Actions\CrearBloqueoAction;
use App\Modules\Reservas\Actions\ListarSolicitudesAction;
use App\Modules\Reservas\Actions\RechazarReservaAction;
use App\Modules\Reservas\Models\BloqueoArea;
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

    public function agenda(Request $request, ConsultarAgendaAction $agenda): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'dias' => ['sometimes', 'integer', 'min:1', 'max:'.ConsultarAgendaAction::MAX_DIAS],
        ], ['desde.date_format' => 'La fecha no es válida.']);

        return ApiResponse::ok($agenda->execute($d['desde'] ?? null, (int) ($d['dias'] ?? 7)));
    }

    public function cancelar(Request $request, CancelarReservaAdminAction $cancelar, int $reserva): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:200']], [
            'motivo.required' => 'Escribe el motivo de la cancelación.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);
        $cancelar->execute($reserva, trim($d['motivo']));

        return ApiResponse::ok(null, message: 'Reserva cancelada. Se avisó al residente.');
    }

    public function bloquear(Request $request, CrearBloqueoAction $crear): JsonResponse
    {
        $d = $request->validate([
            'amenidad_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'inicio' => ['sometimes', 'nullable', 'date_format:H:i'],
            'fin' => ['sometimes', 'nullable', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'min:3', 'max:120'],
            'cancelar_reservas' => ['sometimes', 'boolean'],
        ], [
            'amenidad_id.required' => 'Elige el área.',
            'fecha.required' => 'Elige la fecha.',
            'motivo.required' => 'Escribe el motivo del bloqueo.',
            'motivo.min' => 'El motivo es muy corto.',
        ]);

        $r = $crear->execute($this->usuario($request), (int) $d['amenidad_id'], $d['fecha'], $d['inicio'] ?? null, $d['fin'] ?? null, trim($d['motivo']), (bool) ($d['cancelar_reservas'] ?? false));

        return ApiResponse::created(['id' => $r['bloqueo']->id, 'reservas_canceladas' => $r['canceladas']], message: $r['canceladas'] > 0 ? "Área bloqueada. Se cancelaron {$r['canceladas']} reserva(s) y se avisó a los residentes." : 'Área bloqueada.');
    }

    public function desbloquear(int $bloqueo): JsonResponse
    {
        BloqueoArea::query()->findOrFail($bloqueo)->delete();

        return ApiResponse::ok(null, message: 'Bloqueo quitado.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
