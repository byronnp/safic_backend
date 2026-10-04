<?php

namespace App\Core\Auth\Http\Controllers;

use App\Core\Auth\Http\Requests\AceptarInvitacionRequest;
use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Primer ingreso por invitación: ver a quién corresponde el enlace y crear la
 * contraseña. Al aceptar, la SPA inicia sesión con el correo y la contraseña nueva.
 */
class InvitacionController
{
    public function __construct(private readonly InvitacionService $invitaciones) {}

    public function show(string $token): JsonResponse
    {
        $invitacion = $this->invitaciones->vigente($token);

        return ApiResponse::ok([
            'nombre' => $invitacion->user->name,
            'email' => $invitacion->user->email,
            'condominio' => $invitacion->condominio->nombre,
            'expira_en' => $invitacion->expira_en->toIso8601String(),
            'aviso_privacidad_version' => (string) config('safic.aviso_privacidad_version'),
        ]);
    }

    public function aceptar(string $token, AceptarInvitacionRequest $request): JsonResponse
    {
        $leida = $request->validated('aviso_privacidad_version');
        if (is_string($leida) && $leida !== config('safic.aviso_privacidad_version')) {
            throw new ApiException('AVISO_ACTUALIZADO', 'El aviso de privacidad se actualizó. Léelo de nuevo y vuelve a aceptarlo.', 409);
        }

        $user = $this->invitaciones->aceptar(
            $token,
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::ok(['email' => $user->email], message: 'Contraseña creada. Ya puedes iniciar sesión.');
    }
}
