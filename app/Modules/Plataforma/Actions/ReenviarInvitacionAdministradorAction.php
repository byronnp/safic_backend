<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Mail\InvitacionAdministradorMail;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Caso de uso del super admin: reenviar la invitación a un administrador que todavía
 * no creó su contraseña y, si hace falta, corregir su correo. Anula el enlace
 * anterior (InvitacionService::crear) y envía uno nuevo.
 */
final class ReenviarInvitacionAdministradorAction
{
    public function __construct(private readonly InvitacionService $invitaciones) {}

    public function execute(Condominio $condominio, User $administrador, ?string $email, User $superAdmin): User
    {
        return DB::transaction(function () use ($condominio, $administrador, $email, $superAdmin): User {
            $administrador = User::query()->whereKey($administrador->id)->lockForUpdate()->firstOrFail();

            if (! $this->esAdministrador($condominio, $administrador)) {
                throw new ApiException('NO_ENCONTRADO', 'Esa persona no es administradora de este condominio.', 404);
            }

            if ($administrador->activo) {
                throw new ApiException('ADMINISTRADOR_ACTIVO', 'Esta persona ya creó su contraseña: no necesita otra invitación.', 409);
            }

            if ($email !== null && $email !== $administrador->email) {
                $administrador->forceFill(['email' => $email])->save();
            }

            $token = $this->invitaciones->crear($administrador, $condominio, $superAdmin);

            Mail::to($administrador->email)->queue(new InvitacionAdministradorMail(
                nombre: $administrador->name,
                condominio: $condominio->nombre,
                enlace: config('safic.frontend_url').'/invitacion/'.$token,
                diasVigencia: InvitacionService::DIAS_VIGENCIA,
            ));

            return $administrador;
        });
    }

    private function esAdministrador(Condominio $condominio, User $user): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.condominio_id', $condominio->id)
            ->where('roles.name', Rol::Administrador->value)
            ->exists();
    }
}
