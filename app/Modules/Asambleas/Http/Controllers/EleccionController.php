<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Actions\CerrarEleccionAction;
use App\Modules\Asambleas\Actions\ListarCandidatosEleccionAction;
use App\Modules\Asambleas\Actions\PostularCandidatoAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Elección de directiva: candidatos por cargo y cierre del punto con los nombramientos.
 */
class EleccionController
{
    public function index(ListarCandidatosEleccionAction $listar, int $punto): JsonResponse
    {
        return ApiResponse::ok($listar->execute($punto));
    }

    public function postular(Request $request, PostularCandidatoAction $postular, ListarCandidatosEleccionAction $listar, int $punto): JsonResponse
    {
        $d = $request->validate([
            'persona_id' => ['required', 'integer'],
            'cargo' => ['required', 'string', Rule::in(['presidente', 'vicepresidente', 'secretario', 'tesorero'])],
        ], ['persona_id.required' => 'Elige a la persona.', 'cargo.required' => 'Elige el cargo.', 'cargo.in' => 'Ese cargo no existe en la directiva.']);
        /** @var User $user */
        $user = $request->user();
        $postular->postular($user, $punto, (int) $d['persona_id'], $d['cargo']);

        return ApiResponse::created($listar->execute($punto), message: 'Candidato postulado.');
    }

    public function retirar(PostularCandidatoAction $postular, int $candidato): JsonResponse
    {
        $postular->retirar($candidato);

        return ApiResponse::ok(null, message: 'Candidatura retirada.');
    }

    public function cerrar(Request $request, CerrarEleccionAction $cerrar, Calendario $calendario, int $punto): JsonResponse
    {
        $hoy = CarbonImmutable::parse($calendario->hoy());
        $d = $request->validate([
            // Mismas reglas que al nombrar un cargo desde Usuarios: termina después de hoy y dura hasta 4 años
            'periodo_hasta' => ['required', 'date_format:Y-m-d', 'after:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->addYears(4)->toDateString()],
            'acepta' => ['sometimes', 'array'],
            'acepta.*' => ['string', Rule::in(['presidente', 'vicepresidente', 'secretario', 'tesorero'])],
        ], [
            'periodo_hasta.required' => 'Elige hasta cuándo dura el periodo.', 'periodo_hasta.date_format' => 'La fecha no es válida.',
            'periodo_hasta.after' => 'El periodo debe terminar después de hoy.', 'periodo_hasta.before_or_equal' => 'El periodo puede durar hasta 4 años.',
        ]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($cerrar->execute($user, $punto, $d['periodo_hasta'], $d['acepta'] ?? []), message: 'Elección cerrada.');
    }
}
