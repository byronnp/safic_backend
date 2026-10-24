<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Asambleas\Actions\AbrirRegistroAction;
use App\Modules\Asambleas\Actions\CalcularQuorumAction;
use App\Modules\Asambleas\Actions\RegistrarAsistenciaAction;
use App\Modules\Asambleas\Actions\RegistrarSalidaAction;
use App\Modules\Asambleas\Actions\VerAsistenciaAction;
use App\Modules\Asambleas\Models\Asamblea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asistencia y quórum de la asamblea: abrir el registro, marcar entrada y salida, y el quórum en vivo.
 */
class AsistenciaController
{
    public function abrir(AbrirRegistroAction $abrir, CalcularQuorumAction $quorum, int $asamblea): JsonResponse
    {
        $a = $abrir->execute($asamblea);

        return ApiResponse::ok($quorum->execute($a), message: 'Registro de asistencia abierto.');
    }

    public function index(VerAsistenciaAction $ver, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($ver->execute($asamblea));
    }

    public function registrar(Request $request, RegistrarAsistenciaAction $registrar, CalcularQuorumAction $quorum, int $asamblea): JsonResponse
    {
        $d = $request->validate(['unidad_id' => ['sometimes', 'integer'], 'asistente_persona_id' => ['sometimes', 'nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $registrar->execute($user, $asamblea, $d);

        return ApiResponse::ok($quorum->execute(Asamblea::query()->findOrFail($asamblea)), message: 'Asistencia registrada.');
    }

    public function salida(Request $request, RegistrarSalidaAction $salida, CalcularQuorumAction $quorum, int $asamblea): JsonResponse
    {
        $d = $request->validate(['unidad_id' => ['sometimes', 'integer']]);
        /** @var User $user */
        $user = $request->user();
        $salida->execute($user, $asamblea, $d);

        return ApiResponse::ok($quorum->execute(Asamblea::query()->findOrFail($asamblea)), message: 'Salida registrada.');
    }

    public function quorum(CalcularQuorumAction $quorum, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($quorum->execute(Asamblea::query()->findOrFail($asamblea)));
    }
}
