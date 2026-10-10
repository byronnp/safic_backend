<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Audit\BitacoraPlataforma;
use App\Core\Auth\Services\DobleFactorService;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso de uso del soporte de la plataforma: restablece la verificación en dos pasos de cualquier
 * cuenta que perdió su teléfono y sus códigos (el caso típico es un administrador, que no puede
 * pedírselo a nadie de su condominio). Exige el motivo y queda en la bitácora de plataforma.
 */
final class RestablecerDobleFactorPlataformaAction
{
    public function __construct(private readonly DobleFactorService $dobleFactor) {}

    public function execute(int $userId, string $motivo, User $actor): User
    {
        return DB::transaction(function () use ($userId, $motivo, $actor): User {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($user->id === $actor->id) {
                throw new ApiException('RESTABLECIMIENTO_PROPIO', 'No puedes restablecer tu propia verificación: lo hace otra persona de la plataforma.', 422);
            }
            if (! $user->tieneDobleFactor()) {
                throw new ApiException('DOBLE_FACTOR_NO_ACTIVO', 'Esta persona no tiene la verificación en dos pasos activa.', 409);
            }

            $this->dobleFactor->desactivar($user);

            BitacoraPlataforma::registrar('actualizado', 'usuario', $user->id, $user->name, ['doble_factor' => 'activo'], ['doble_factor' => 'restablecido', 'motivo' => $motivo]);
            Log::warning('seguridad.doble_factor_restablecido', ['actor_id' => $actor->id, 'user_id' => $user->id, 'motivo' => $motivo, 'ambito' => 'plataforma']);

            return $user;
        });
    }
}
