<?php

namespace App\Modules\Reservas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Reservas\Actions\AprobarReservaAction;
use App\Modules\Reservas\Actions\CancelarReservaAdminAction;
use App\Modules\Reservas\Actions\ConsultarAgendaAction;
use App\Modules\Reservas\Actions\CrearBloqueoAction;
use App\Modules\Reservas\Actions\ListarDanosDeReservasAction;
use App\Modules\Reservas\Actions\ListarGarantiasDeReservasAction;
use App\Modules\Reservas\Actions\ListarSolicitudesAction;
use App\Modules\Reservas\Actions\RechazarReservaAction;
use App\Modules\Reservas\Actions\RegistrarDevolucionGarantiaAction;
use App\Modules\Reservas\Actions\ResolverDanosDeReservaAction;
use App\Modules\Reservas\Actions\VerDanosDeReservaAction;
use App\Modules\Reservas\Models\BloqueoArea;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function listaDanos(ListarDanosDeReservasAction $lista): JsonResponse
    {
        return ApiResponse::ok($lista->execute());
    }

    public function garantias(ListarGarantiasDeReservasAction $lista): JsonResponse
    {
        return ApiResponse::ok($lista->execute());
    }

    public function devolverGarantia(Request $request, RegistrarDevolucionGarantiaAction $devolver, int $reserva): JsonResponse
    {
        $d = $request->validate([
            'forma' => ['required', Rule::in(Reserva::FORMAS_DEVOLUCION)],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'referencia' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'forma.required' => 'Indica cómo se devolvió el dinero.',
            'forma.in' => 'La forma de devolución no es válida.',
            'fecha.required' => 'Indica el día de la devolución.',
            'fecha.date_format' => 'La fecha no es válida.',
            'referencia.max' => 'La referencia tiene máximo 40 caracteres.',
        ]);
        $referencia = isset($d['referencia']) && trim($d['referencia']) !== '' ? trim($d['referencia']) : null;
        $r = $devolver->execute($this->usuario($request), $reserva, $d['forma'], $d['fecha'], $referencia);

        return ApiResponse::ok(['reserva_id' => $r->id, 'monto' => (string) $r->garantia_devuelta_monto], message: 'Devolución de la garantía registrada.');
    }

    public function danos(VerDanosDeReservaAction $danos, int $reserva): JsonResponse
    {
        return ApiResponse::ok($danos->execute($reserva));
    }

    public function resolverDanos(Request $request, ResolverDanosDeReservaAction $resolver, VerDanosDeReservaAction $ver, int $reserva): JsonResponse
    {
        $d = $request->validate([
            'decision' => ['required', Rule::in([Reserva::DANOS_SIN_CARGO, Reserva::DANOS_DESCONTAR_GARANTIA, Reserva::DANOS_COBRAR_CUENTA])],
            'monto' => ['required_unless:decision,'.Reserva::DANOS_SIN_CARGO, 'nullable', 'numeric', 'gt:0', 'max:99999.99', 'decimal:0,2'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:300'],
        ], [
            'decision.required' => 'Elige qué hacer con los daños.',
            'decision.in' => 'Esa decisión no existe.',
            'monto.required_unless' => 'Indica el valor del daño.',
            'monto.gt' => 'El valor del daño debe ser mayor que cero.',
            'monto.decimal' => 'Usa hasta dos decimales.',
            'monto.max' => 'El valor es demasiado alto.',
            'nota.max' => 'La nota tiene máximo 300 caracteres.',
        ]);
        $nota = isset($d['nota']) && trim($d['nota']) !== '' ? trim($d['nota']) : null;
        $resolver->execute($this->usuario($request), $reserva, $d['decision'], isset($d['monto']) ? (string) $d['monto'] : null, $nota);

        return ApiResponse::ok($ver->execute($reserva), message: $d['decision'] === Reserva::DANOS_COBRAR_CUENTA ? 'Daño cobrado en la cuenta de la unidad.' : 'Decisión guardada.');
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
