<?php

namespace App\Modules\Usuarios\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Usuarios\Actions\AsignarCargoAction;
use App\Modules\Usuarios\Actions\ListarCandidatosDirectivaAction;
use App\Modules\Usuarios\Actions\ListarDirectivaAction;
use App\Modules\Usuarios\Http\Requests\AsignarCargoRequest;
use Illuminate\Http\JsonResponse;

/**
 * Directiva del condominio: quién ocupa cada cargo y cómo se cambia.
 */
class DirectivaController
{
    public function index(ListarDirectivaAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function candidatos(ListarCandidatosDirectivaAction $candidatos, string $cargo): JsonResponse
    {
        return ApiResponse::ok($candidatos->execute($this->cargo($cargo)));
    }

    public function asignar(AsignarCargoRequest $request, AsignarCargoAction $asignar, ListarDirectivaAction $listar, string $cargo): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{persona_id: int, acta: string, periodo_hasta: string} $datos */
        $datos = $request->validated();

        $asignar->execute($this->cargo($cargo), (int) $datos['persona_id'], $datos['acta'], $datos['periodo_hasta'], $actor);

        $item = collect($listar->execute())->firstWhere('cargo', $cargo);

        return ApiResponse::ok($item, message: 'Cargo asignado.');
    }

    /** Solo los cuatro cargos de la directiva; cualquier otro nombre responde 404. */
    private function cargo(string $cargo): Rol
    {
        $rol = Rol::tryFrom($cargo);
        abort_unless($rol !== null && $rol->esCargo(), 404);

        return $rol;
    }
}
