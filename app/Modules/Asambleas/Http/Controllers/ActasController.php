<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Asambleas\Actions\CerrarAsambleaAction;
use App\Modules\Asambleas\Actions\FirmarActaAction;
use App\Modules\Asambleas\Actions\ListarResolucionesAction;
use App\Modules\Asambleas\Actions\PdfDeActaAction;
use App\Modules\Asambleas\Actions\VerActaAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cierre de la asamblea, acta en PDF con las firmas del presidente y el secretario, y resoluciones.
 */
class ActasController
{
    public function cerrar(Request $request, CerrarAsambleaAction $cerrar, VerActaAction $ver, int $asamblea): JsonResponse
    {
        $d = $request->validate(['confirmar_pendientes' => ['sometimes', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $cerrar->execute($user, $asamblea, (bool) ($d['confirmar_pendientes'] ?? false));

        return ApiResponse::ok($ver->execute($user, $asamblea), message: 'Asamblea cerrada. El acta espera las firmas del presidente y el secretario.');
    }

    public function show(Request $request, VerActaAction $ver, int $asamblea): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($ver->execute($user, $asamblea));
    }

    public function pdf(Request $request, PdfDeActaAction $pdf, int $asamblea): Response
    {
        /** @var User $user */
        $user = $request->user();
        $r = $pdf->execute($user, $asamblea);

        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function firmar(Request $request, FirmarActaAction $firmar, VerActaAction $ver, int $acta): JsonResponse
    {
        $d = $request->validate(['presidente_ausente' => ['sometimes', 'boolean']]);
        /** @var User $user */
        $user = $request->user();
        $a = $firmar->execute($user, $acta, (bool) ($d['presidente_ausente'] ?? false));

        return ApiResponse::ok($ver->execute($user, $a->asamblea_id), message: $a->estaFirmada() ? 'Acta firmada y publicada.' : 'Firma registrada.');
    }

    public function resoluciones(Request $request, ListarResolucionesAction $listar): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($listar->execute($user));
    }
}
