<?php

namespace App\Modules\Usuarios\Services;

use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * La cuenta con la que una persona entra al sistema: la busca por correo o la crea inactiva,
 * la suma al condominio y, si aún no creó su contraseña, le envía la invitación. Lo comparten
 * la directiva y el acceso de los residentes.
 */
final class CuentasDePersonas
{
    public function __construct(private readonly InvitacionService $invitaciones) {}

    /**
     * Si no existe se crea inactiva; si ya está en el condominio se reutiliza (y se reactiva
     * si estaba desactivada).
     *
     * @param  array{nombre: string, email: string|null, cedula: string|null}  $persona
     */
    public function cuenta(array $persona, Condominio $condominio): User
    {
        $email = mb_strtolower((string) $persona['email']);
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            if ($persona['cedula'] !== null && User::query()->where('cedula', $persona['cedula'])->exists()) {
                throw new ApiException('CEDULA_EN_USO', 'La cédula de esta persona ya pertenece a otro usuario con un correo distinto. Corrige el correo en su ficha.', 422);
            }

            $user = User::create([
                'name' => $persona['nombre'],
                'cedula' => $persona['cedula'],
                'email' => $email,
                'password' => Str::random(48),
                'activo' => false,
            ]);
        }

        $membresia = $user->membresias()->where('condominio_id', $condominio->id)->first();
        if ($membresia === null) {
            $user->membresias()->create([
                'condominio_id' => $condominio->id,
                'es_principal' => ! $user->membresias()->where('es_principal', true)->exists(),
                'activo' => true,
            ]);
        } elseif (! $membresia->activo) {
            $membresia->update(['activo' => true]);
        }

        return $user;
    }

    /** Envía el enlace para crear la contraseña si la cuenta aún no está activa. Devuelve si lo envió. */
    public function invitarSiHaceFalta(User $user, Condominio $condominio, string $perfil, User $actor): bool
    {
        if ($user->activo) {
            return false;
        }

        $token = $this->invitaciones->crear($user, $condominio, $actor);
        Mail::to($user->email)->queue(new InvitacionUsuarioMail(
            nombre: $user->name,
            condominio: $condominio->nombre,
            perfil: $perfil,
            enlace: config('safic.frontend_url').'/invitacion/'.$token,
            diasVigencia: InvitacionService::DIAS_VIGENCIA,
        ));

        return true;
    }
}
