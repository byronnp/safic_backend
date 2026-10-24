<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Asambleas\Actions\AbrirVotacionAction;
use App\Modules\Asambleas\Actions\AuditoriaDeVotacionAction;
use App\Modules\Asambleas\Actions\CerrarVotacionAction;
use App\Modules\Asambleas\Actions\EmitirVotoAction;
use App\Modules\Asambleas\Actions\VerVotacionAction;
use App\Modules\Asambleas\Models\Votacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Votaciones por punto del orden del día: abrir, votar, cerrar y auditar.
 */
class VotacionesController
{
    public function abrir(Request $request, AbrirVotacionAction $abrir, VerVotacionAction $ver, int $punto): JsonResponse
    {
        $d = $request->validate(['continuar_sin_quorum' => ['sometimes', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $v = $abrir->execute($user, $punto, (bool) ($d['continuar_sin_quorum'] ?? false));

        return ApiResponse::created($ver->execute($v), message: 'Votación abierta.');
    }

    public function show(VerVotacionAction $ver, int $votacion): JsonResponse
    {
        return ApiResponse::ok($ver->execute(Votacion::query()->findOrFail($votacion)));
    }

    public function cerrar(Request $request, CerrarVotacionAction $cerrar, VerVotacionAction $ver, int $votacion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($ver->execute($cerrar->execute($user, $votacion)), message: 'Votación cerrada.');
    }

    public function votar(Request $request, EmitirVotoAction $emitir, int $votacion): JsonResponse
    {
        $d = $request->validate(['opcion' => ['required', Rule::in(Votacion::OPCIONES)], 'unidad_id' => ['sometimes', 'integer']], [
            'opcion.required' => 'Elige Sí, No o Abstención.', 'opcion.in' => 'La opción no es válida.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $r = $emitir->execute($user, $votacion, $d['opcion'], isset($d['unidad_id']) ? (int) $d['unidad_id'] : null);

        return ApiResponse::created(['unidades' => $r], message: 'Voto registrado.');
    }

    public function auditoria(AuditoriaDeVotacionAction $auditoria, int $votacion): Response
    {
        $r = $auditoria->execute($votacion);

        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
