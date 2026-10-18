<?php

namespace App\Modules\Garita\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Garita\Actions\CancelarSolicitudIngresoAction;
use App\Modules\Garita\Actions\ListarMisSolicitudesIngresoAction;
use App\Modules\Garita\Actions\PedirPermisoIngresoAction;
use App\Modules\Garita\Actions\RegistrarIngresoDeSolicitudAction;
use App\Modules\Garita\Actions\ResponderSolicitudIngresoAction;
use App\Modules\Garita\Models\SolicitudIngreso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «¿Lo dejo pasar?»: el guardia le pregunta al residente desde la app y el residente responde.
 */
class SolicitudesIngresoController
{
    public function pedir(Request $request, PedirPermisoIngresoAction $pedir): JsonResponse
    {
        $d = $request->validate([
            'unidad_codigo' => ['required', 'string', 'max:20'],
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'tipo' => ['required', 'in:visita,delivery,proveedor'],
            'placa' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{2,3}-?[0-9]{3,4}$/'],
        ], [
            'unidad_codigo.required' => 'Indica la unidad.',
            'nombre.required' => 'Escribe el nombre.',
            'nombre.min' => 'El nombre es muy corto.',
            'placa.regex' => 'La placa no es válida (ej. PDQ-3321).',
        ]);

        $s = $pedir->execute($this->usuario($request), $d['unidad_codigo'], trim($d['nombre']), $d['tipo'], $d['placa'] ?? null);

        return ApiResponse::created(['id' => $s->id], message: 'Se le preguntó al residente. Tiene '.SolicitudIngreso::MINUTOS_PARA_RESPONDER.' minutos para responder.');
    }

    public function ingreso(Request $request, RegistrarIngresoDeSolicitudAction $registrar, int $solicitud): JsonResponse
    {
        $a = $registrar->execute($this->usuario($request), $solicitud);

        return ApiResponse::created(['id' => $a->id, 'nombre' => $a->nombre, 'estado' => $a->estado], message: 'Ingreso registrado.');
    }

    public function cancelar(CancelarSolicitudIngresoAction $cancelar, int $solicitud): JsonResponse
    {
        $cancelar->execute($solicitud);

        return ApiResponse::ok(null, message: 'Solicitud retirada.');
    }

    public function mias(Request $request, ListarMisSolicitudesIngresoAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function responder(Request $request, ResponderSolicitudIngresoAction $responder, int $solicitud): JsonResponse
    {
        $d = $request->validate(['permitir' => ['required', 'boolean']], ['permitir.required' => 'Indica si permites el ingreso.']);
        $s = $responder->execute($this->usuario($request), $solicitud, (bool) $d['permitir']);

        return ApiResponse::ok(['id' => $s->id, 'estado' => $s->estado], message: $s->estado === 'permitida' ? 'Ingreso permitido. Se avisó a la garita.' : 'Ingreso rechazado. Se avisó a la garita.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
