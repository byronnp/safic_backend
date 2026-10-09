<?php

namespace App\Modules\Usuarios\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Usuarios\Actions\ActualizarUsuarioAction;
use App\Modules\Usuarios\Actions\InvitarUsuarioAction;
use App\Modules\Usuarios\Actions\ListarUsuariosAction;
use App\Modules\Usuarios\Actions\ReenviarInvitacionUsuarioAction;
use App\Modules\Usuarios\Http\Requests\ActualizarUsuarioRequest;
use App\Modules\Usuarios\Http\Requests\InvitarUsuarioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Equipo del condominio activo: quién tiene acceso, con qué perfil y cuánto del cupo del plan usa.
 */
class UsuarioController
{
    public function index(Request $request, ListarUsuariosAction $listar): JsonResponse
    {
        $resultado = $listar->execute($this->actor($request)->id);

        return ApiResponse::ok($resultado['usuarios'], ['cupo' => $resultado['cupo']]);
    }

    public function store(InvitarUsuarioRequest $request, InvitarUsuarioAction $invitar, ListarUsuariosAction $listar): JsonResponse
    {
        /** @var array{nombre: string, cedula: string, email: string, celular?: string|null, rol: string, acceso_hasta?: string|null} $datos */
        $datos = $request->validated();
        $resultado = $invitar->execute($datos, $this->actor($request));

        return ApiResponse::created(
            $this->uno($listar, $resultado['user']->id, $this->actor($request)->id) + ['invitacion_enviada' => $resultado['invitacion_enviada']],
            message: $resultado['invitacion_enviada'] ? 'Invitación enviada.' : 'Persona agregada al equipo.',
        );
    }

    public function update(ActualizarUsuarioRequest $request, ActualizarUsuarioAction $actualizar, ListarUsuariosAction $listar, int $usuario): JsonResponse
    {
        /** @var array{rol?: string, acceso_hasta?: string|null, activo?: bool} $datos */
        $datos = $request->validated();
        $actualizar->execute($usuario, $datos, $this->actor($request));

        return ApiResponse::ok($this->uno($listar, $usuario, $this->actor($request)->id), message: 'Cambios guardados.');
    }

    public function reenviar(Request $request, ReenviarInvitacionUsuarioAction $reenviar, ListarUsuariosAction $listar, int $usuario): JsonResponse
    {
        $reenviar->execute($usuario, $this->actor($request));

        return ApiResponse::ok($this->uno($listar, $usuario, $this->actor($request)->id), message: 'Invitación reenviada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function uno(ListarUsuariosAction $listar, int $userId, int $actorId): array
    {
        return collect($listar->execute($actorId)['usuarios'])->firstWhere('id', $userId) ?? [];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
