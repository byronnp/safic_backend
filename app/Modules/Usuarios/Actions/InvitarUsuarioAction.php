<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Auth\Services\InvitacionService;
use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Usuarios\Mail\InvitacionUsuarioMail;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Caso de uso: sumar a una persona al equipo del condominio con un perfil. Si el correo
 * ya tiene cuenta se asocia (sin invitación si ya creó su contraseña); si no, se crea
 * inactiva y se le envía el enlace para crear su contraseña. Respeta el cupo del plan.
 */
final class InvitarUsuarioAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly LimiteUsuarios $limite,
        private readonly PerfilesUsuario $perfiles,
        private readonly InvitacionService $invitaciones,
    ) {}

    /**
     * @param  array{nombre: string, cedula: string, email: string, celular?: string|null, rol: string, acceso_hasta?: string|null}  $datos
     * @return array{user: User, invitacion_enviada: bool}
     */
    public function execute(array $datos, User $invitadoPor): array
    {
        return DB::transaction(function () use ($datos, $invitadoPor): array {
            $condominio = Condominio::query()->findOrFail($this->tenant->require());
            $rol = Rol::from($datos['rol']);
            $email = mb_strtolower($datos['email']);

            $user = User::query()->where('email', $email)->first();

            $otro = User::query()->where('cedula', $datos['cedula'])
                ->when($user !== null, fn ($q) => $q->whereKeyNot($user->id))
                ->exists();
            if ($otro) {
                throw new ApiException('CEDULA_EN_USO', 'Esa cédula ya pertenece a otro usuario con un correo distinto.', 422);
            }

            if ($user !== null && $user->membresias()->where('condominio_id', $condominio->id)->exists()) {
                throw new ApiException('USUARIO_YA_EXISTE', 'Esa persona ya tiene acceso a este condominio. Búscala en la lista para cambiar su perfil o reactivarla.', 409);
            }

            if ($rol->cuentaParaCupo()) {
                $this->limite->asegurarCupo();
            }

            if ($user === null) {
                $user = User::create([
                    'name' => $datos['nombre'],
                    'cedula' => $datos['cedula'],
                    'email' => $email,
                    'celular' => $datos['celular'] ?? null,
                    'password' => Str::random(48),
                    'activo' => false,
                ]);
            } elseif ($user->cedula === null) {
                $user->forceFill(['cedula' => $datos['cedula']])->save();
            }

            $user->membresias()->create([
                'condominio_id' => $condominio->id,
                'es_principal' => ! $user->membresias()->where('es_principal', true)->exists(),
                'activo' => true,
                'acceso_hasta' => $datos['acceso_hasta'] ?? null,
            ]);

            $this->perfiles->asignar($user, $rol);

            $enviar = ! $user->activo;
            if ($enviar) {
                $token = $this->invitaciones->crear($user, $condominio, $invitadoPor);

                Mail::to($user->email)->queue(new InvitacionUsuarioMail(
                    nombre: $user->name,
                    condominio: $condominio->nombre,
                    perfil: $rol->etiqueta(),
                    enlace: config('safic.frontend_url').'/invitacion/'.$token,
                    diasVigencia: InvitacionService::DIAS_VIGENCIA,
                ));
            }

            return ['user' => $user, 'invitacion_enviada' => $enviar];
        });
    }
}
