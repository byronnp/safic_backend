<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Permissions\Rol;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Membresia;
use App\Modules\Usuarios\Services\PerfilesUsuario;

/**
 * Consulta pública del módulo Usuarios: las personas con acceso al condominio, su perfil,
 * si consumen cupo del plan y el uso del cupo.
 */
final class ListarUsuariosAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PerfilesUsuario $perfiles,
        private readonly LimiteUsuarios $limite,
        private readonly Calendario $calendario,
    ) {}

    /**
     * @return array{usuarios: list<array<string, mixed>>, cupo: array{plan: string|null, limite: int|null, usados: int}}
     */
    public function execute(int $actorId): array
    {
        $membresias = Membresia::query()
            ->with('user')
            ->where('condominio_id', $this->tenant->require())
            ->get()
            ->sortBy(fn (Membresia $m) => mb_strtolower($m->user->name))
            ->values();

        $roles = $this->perfiles->de($membresias->pluck('user_id')->all());
        $hoy = $this->calendario->hoy();

        $usuarios = $membresias->map(function (Membresia $m) use ($roles, $hoy, $actorId): array {
            /** @var User $user */
            $user = $m->user;
            $nombres = $roles[$user->id] ?? [];
            $vencido = $m->acceso_hasta !== null && $m->acceso_hasta->toDateString() < $hoy;
            $perfil = collect(Rol::asignables())->first(fn (Rol $r) => in_array($r->value, $nombres, true));

            return [
                'id' => $user->id,
                'nombre' => $user->name,
                'email' => $user->email,
                'celular' => $user->celular,
                'roles' => $nombres,
                'perfil' => $perfil?->value,
                'cuenta_cupo' => $m->activo && ! $vencido && $this->consumeCupo($nombres),
                'estado' => match (true) {
                    ! $m->activo => 'desactivado',
                    $vencido => 'vencido',
                    ! $user->activo => 'pendiente',
                    default => 'activo',
                },
                'acceso_hasta' => $m->acceso_hasta?->toDateString(),
                'es_yo' => $user->id === $actorId,
                'doble_factor' => $user->tieneDobleFactor(),
            ];
        })->all();

        $plan = $this->limite->plan();

        return [
            'usuarios' => $usuarios,
            'cupo' => ['plan' => $plan['nombre'] ?? null, 'limite' => $plan['limite'] ?? null, 'usados' => $this->limite->usados()],
        ];
    }

    /** @param  list<string>  $nombres */
    private function consumeCupo(array $nombres): bool
    {
        return array_intersect($nombres, Rol::nombresQueCuentanParaCupo()) !== [];
    }
}
