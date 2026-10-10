<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Auth\Services\DobleFactorService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Membresia;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso de uso: el administrador restablece la verificación en dos pasos de alguien de su equipo
 * que perdió su teléfono y sus códigos de respaldo. Queda sin 2FA; si es contador, deberá
 * configurarla de nuevo para volver a trabajar. No sirve para la propia cuenta ni para otro
 * administrador (eso lo hace la plataforma): así nadie se restablece a sí mismo ni a su par.
 */
final class RestablecerDobleFactorAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DobleFactorService $dobleFactor,
        private readonly PerfilesUsuario $perfiles,
    ) {}

    public function execute(int $userId, string $motivo, User $actor): User
    {
        return DB::transaction(function () use ($userId, $motivo, $actor): User {
            $condominioId = $this->tenant->require();

            Membresia::query()->where('condominio_id', $condominioId)->where('user_id', $userId)->firstOrFail();
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($user->id === $actor->id) {
                throw new ApiException('RESTABLECIMIENTO_PROPIO', 'Para tu propia cuenta usa un código de respaldo; si los perdiste, pídele ayuda a la plataforma.', 422);
            }
            if (in_array(Rol::Administrador->value, $this->perfiles->de([$user->id])[$user->id] ?? [], true)) {
                throw new ApiException('SOLO_PLATAFORMA', 'La verificación de otro administrador la restablece el soporte de la plataforma.', 403);
            }
            if (! $user->tieneDobleFactor()) {
                throw new ApiException('DOBLE_FACTOR_NO_ACTIVO', 'Esta persona no tiene la verificación en dos pasos activa.', 409);
            }

            $this->dobleFactor->desactivar($user);
            Log::warning('seguridad.doble_factor_restablecido', [
                'actor_id' => $actor->id, 'user_id' => $user->id, 'condominio_id' => $condominioId, 'motivo' => $motivo,
            ]);

            return $user;
        });
    }
}
