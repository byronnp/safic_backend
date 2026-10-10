<?php

namespace App\Core\Auth\Http\Controllers;

use App\Core\Auth\Services\DobleFactorService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Verificación en dos pasos de la propia cuenta: activar (QR → código → códigos de respaldo),
 * regenerar los códigos de respaldo y desactivar. Cada paso sensible pide la contraseña.
 */
class DobleFactorController
{
    public function __construct(private readonly DobleFactorService $servicio) {}

    public function preparar(Request $request): JsonResponse
    {
        $user = $this->usuario($request);
        $this->servicio->exigirContrasena($user, $this->contrasena($request));

        return ApiResponse::ok($this->servicio->preparar($user), message: 'Escanea el código QR con tu app autenticadora.');
    }

    public function confirmar(Request $request): JsonResponse
    {
        $user = $this->usuario($request);
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:20']], ['codigo.required' => 'Escribe el código de tu app.']);

        $codigos = $this->servicio->confirmar($user, $datos['codigo']);
        Log::info('seguridad.doble_factor_activado', ['user_id' => $user->id]);

        return ApiResponse::ok(['codigos_respaldo' => $codigos], message: 'Verificación en dos pasos activada. Guarda tus códigos de respaldo.');
    }

    public function regenerarCodigos(Request $request): JsonResponse
    {
        $user = $this->usuario($request);
        $this->exigirContrasenaYCodigo($request, $user);

        Log::info('seguridad.doble_factor_codigos_regenerados', ['user_id' => $user->id]);

        return ApiResponse::ok(['codigos_respaldo' => $this->servicio->regenerarCodigos($user)], message: 'Códigos de respaldo nuevos. Los anteriores ya no sirven.');
    }

    public function desactivar(Request $request): JsonResponse
    {
        $user = $this->usuario($request);

        if ($this->servicio->esObligatoria($user)) {
            throw new ApiException('DOBLE_FACTOR_OBLIGATORIO', 'Tu perfil de contador exige la verificación en dos pasos: no se puede desactivar.', 403);
        }

        $this->exigirContrasenaYCodigo($request, $user);
        $this->servicio->desactivar($user);
        Log::warning('seguridad.doble_factor_desactivado', ['user_id' => $user->id]);

        return ApiResponse::ok(message: 'Verificación en dos pasos desactivada.');
    }

    private function exigirContrasenaYCodigo(Request $request, User $user): void
    {
        $this->servicio->exigirContrasena($user, $this->contrasena($request));

        $codigo = $request->validate(['codigo' => ['required', 'string', 'max:20']], ['codigo.required' => 'Escribe el código de tu app.'])['codigo'];
        if (! $this->servicio->verificar($user, $codigo)) {
            throw ValidationException::withMessages(['codigo' => 'El código no es correcto.']);
        }
    }

    private function contrasena(Request $request): string
    {
        return $request->validate(['password' => ['required', 'string', 'max:255']], ['password.required' => 'Escribe tu contraseña.'])['password'];
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
