<?php

namespace App\Modules\Garita\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Garita\Actions\VerVisitaPublicaAction;
use Illuminate\Http\JsonResponse;

/**
 * Página pública del QR de una visita (sin sesión).
 */
class VisitaPublicaController
{
    public function show(VerVisitaPublicaAction $ver, string $token): JsonResponse
    {
        return ApiResponse::ok($ver->execute($token) ?? abort(404));
    }
}
