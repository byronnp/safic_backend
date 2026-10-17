<?php

namespace App\Modules\Garita\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Garita\Actions\CrearAutorizacionVisitaAction;
use App\Modules\Garita\Actions\ListarMisVisitasAction;
use App\Modules\Garita\Actions\RevocarAutorizacionVisitaAction;
use App\Modules\Garita\Models\AutorizacionVisita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitas del residente: las que autorizó, crear una nueva (con su QR) y revocarla.
 */
class MisVisitasController
{
    public function index(Request $request, ListarMisVisitasAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function store(Request $request, CrearAutorizacionVisitaAction $crear, ListarMisVisitasAction $listar): JsonResponse
    {
        $d = $request->validate([
            'unidad_id' => ['required', 'integer'],
            'visitante' => ['required', 'string', 'min:3', 'max:120'],
            'documento' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'placa' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{2,3}-?[0-9]{3,4}$/'],
            'tipo' => ['required', 'in:'.AutorizacionVisita::UNICA.','.AutorizacionVisita::RECURRENTE],
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            'fecha_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hora_desde' => ['required', 'date_format:H:i'],
            'hora_hasta' => ['required', 'date_format:H:i'],
            'dias_semana' => ['sometimes', 'nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
        ], [
            'visitante.required' => 'Escribe el nombre del visitante.',
            'visitante.min' => 'El nombre es muy corto.',
            'documento.regex' => 'La cédula o el documento no es válido.',
            'placa.regex' => 'La placa no es válida (ej. PDQ-3321).',
            'fecha_desde.required' => 'Elige la fecha.',
            'hora_desde.required' => 'Indica la hora de inicio.',
            'hora_hasta.required' => 'Indica la hora final.',
        ]);

        $a = $crear->execute($this->usuario($request), $d);
        $unidad = collect($listar->execute($this->usuario($request))['unidades'])->firstWhere('unidad_id', $a->unidad_id)['codigo'] ?? '';

        return ApiResponse::created(ListarMisVisitasAction::item($a, $unidad), message: 'Visita autorizada.');
    }

    public function revocar(Request $request, RevocarAutorizacionVisitaAction $revocar, int $visita): JsonResponse
    {
        $revocar->execute($this->usuario($request), $visita);

        return ApiResponse::ok(null, message: 'Autorización revocada. El QR ya no sirve.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
