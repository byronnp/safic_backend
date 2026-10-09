<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Membresia;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso: reenviar la invitación a quien todavía no creó su contraseña. Anula el
 * enlace anterior y envía uno nuevo.
 */
final class ReenviarInvitacionUsuarioAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly InvitacionService $invitaciones,
        private readonly PerfilesUsuario $perfiles,
    ) {}

    public function execute(int $userId, User $actor): User
    {
        return DB::transaction(function () use ($userId, $actor): User {
            $condominio = Condominio::query()->findOrFail($this->tenant->require());

            Membresia::query()->where('condominio_id', $condominio->id)->where('user_id', $userId)->firstOrFail();
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($user->activo) {
                throw new ApiException('USUARIO_ACTIVO', 'Esta persona ya creó su contraseña: no necesita otra invitación.', 409);
            }

            $token = $this->invitaciones->crear($user, $condominio, $actor);

            Mail::to($user->email)->queue(new InvitacionUsuarioMail(
                nombre: $user->name,
                condominio: $condominio->nombre,
                perfil: $this->perfiles->perfilAsignable($user)?->etiqueta() ?? 'Equipo',
                enlace: config('safic.frontend_url').'/invitacion/'.$token,
                diasVigencia: InvitacionService::DIAS_VIGENCIA,
            ));

            return $user;
        });
    }
}
