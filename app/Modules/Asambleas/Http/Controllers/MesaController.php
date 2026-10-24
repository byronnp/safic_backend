<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Asambleas\Actions\InstalarAsambleaAction;
use App\Modules\Asambleas\Actions\VerMesaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La mesa de la asamblea: instalarla y ver el quórum y el orden del día.
 */
class MesaController
{
    public function show(VerMesaAction $mesa, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($mesa->execute($asamblea));
    }

    public function instalar(Request $request, InstalarAsambleaAction $instalar, VerMesaAction $mesa, int $asamblea): JsonResponse
    {
        $d = $request->validate(['convocatoria' => ['required', 'integer', Rule::in([1, 2])], 'presidente_ausente' => ['sometimes', 'boolean']], [
            'convocatoria.required' => 'Indica si se instala en primera o en segunda convocatoria.', 'convocatoria.in' => 'La convocatoria debe ser 1 o 2.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $instalar->execute($user, $asamblea, ['convocatoria' => (int) $d['convocatoria'], 'presidente_ausente' => (bool) ($d['presidente_ausente'] ?? false)]);

        return ApiResponse::ok($mesa->execute($asamblea), message: 'Asamblea instalada.');
    }
}
