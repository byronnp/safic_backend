<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Membresia;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caso de uso: cambiar el perfil, la vigencia del acceso o desactivar/reactivar a una
 * persona del equipo. Nadie se modifica a sí mismo, el condominio no se queda sin
 * administrador y pasar a un perfil que consume cupo respeta el límite del plan.
 */
final class ActualizarUsuarioAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LimiteUsuarios $limite,
        private readonly PerfilesUsuario $perfiles,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @param  array{rol?: string, acceso_hasta?: string|null, activo?: bool}  $datos  Solo lo que cambia
     */
    public function execute(int $userId, array $datos, User $actor): Membresia
    {
        return DB::transaction(function () use ($userId, $datos, $actor): Membresia {
            $condominioId = $this->tenant->require();

            // Siempre dentro del condominio activo: otro condominio responde 404
            $membresia = Membresia::query()
                ->where('condominio_id', $condominioId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()->findOrFail($userId);

            if ($user->id === $actor->id) {
                throw new ApiException('USUARIO_PROPIO', 'No puedes cambiar tu propio acceso. Pídeselo a otro administrador.', 409);
            }

            $antes = $this->estado($membresia, $user);

            $rolNuevo = isset($datos['rol']) ? Rol::from($datos['rol']) : null;
            if (array_key_exists('acceso_hasta', $datos)) {
                $membresia->acceso_hasta = $datos['acceso_hasta'] === null ? null : Carbon::parse($datos['acceso_hasta']);
            }
            if (array_key_exists('activo', $datos)) {
                $membresia->activo = $datos['activo'];
            }

            $perfilFinal = $rolNuevo ?? $this->perfiles->perfilAsignable($user);
            if ($perfilFinal?->requiereVigencia() && $membresia->acceso_hasta === null) {
                throw ValidationException::withMessages(['acceso_hasta' => 'El contador necesita una fecha de vencimiento del acceso.']);
            }

            $despues = $this->estado($membresia, $user, $rolNuevo);

            if ($antes['administrador_vigente'] && ! $despues['administrador_vigente'] && ! $this->quedaOtroAdministrador($user->id)) {
                throw new ApiException('ULTIMO_ADMINISTRADOR', 'El condominio no puede quedarse sin administrador. Nombra a otro antes de este cambio.', 409);
            }

            if (! $antes['consume_cupo'] && $despues['consume_cupo']) {
                $this->limite->asegurarCupo();
            }

            $membresia->save();
            if ($rolNuevo !== null) {
                $this->perfiles->asignar($user, $rolNuevo);
            }

            return $membresia->load('user');
        });
    }

    /**
     * @return array{consume_cupo: bool, administrador_vigente: bool}
     */
    private function estado(Membresia $membresia, User $user, ?Rol $rolNuevo = null): array
    {
        $nombres = $this->perfiles->de([$user->id])[$user->id] ?? [];
        if ($rolNuevo !== null) {
            $asignables = array_map(fn (Rol $r) => $r->value, Rol::asignables());
            $nombres = [...array_diff($nombres, $asignables), $rolNuevo->value];
        }

        $vigente = $membresia->activo
            && ($membresia->acceso_hasta === null || $membresia->acceso_hasta->toDateString() >= $this->calendario->hoy());

        return [
            'consume_cupo' => $vigente && array_intersect($nombres, Rol::nombresQueCuentanParaCupo()) !== [],
            'administrador_vigente' => $vigente && in_array(Rol::Administrador->value, $nombres, true),
        ];
    }

    private function quedaOtroAdministrador(int $excluirUserId): bool
    {
        $hoy = $this->calendario->hoy();

        return DB::table('condominio_user as cu')
            ->join('model_has_roles as mr', function ($join): void {
                $join->on('mr.model_id', '=', 'cu.user_id')
                    ->on('mr.condominio_id', '=', 'cu.condominio_id')
                    ->where('mr.model_type', (new User)->getMorphClass());
            })
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('cu.condominio_id', $this->tenant->require())
            ->where('cu.user_id', '!=', $excluirUserId)
            ->where('cu.activo', true)
            ->where(fn ($q) => $q->whereNull('cu.acceso_hasta')->orWhere('cu.acceso_hasta', '>=', $hoy))
            ->where('r.name', Rol::Administrador->value)
            ->exists();
    }
}
