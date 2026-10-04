<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda de una persona por correo para el paso "Administrador" del asistente:
 * si ya tiene cuenta, se asocia en lugar de invitarla.
 */
class UsuarioPlataformaController
{
    public function buscar(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->query('email', '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ApiResponse::ok(null);
        }

        $user = User::query()->where('email', $email)->withCount('membresias')->first();

        return ApiResponse::ok($user === null ? null : [
            'id' => $user->id,
            'nombre' => $user->name,
            'activo' => (bool) $user->activo,
            'condominios' => (int) $user->getAttribute('membresias_count'),
        ]);
    }
}
