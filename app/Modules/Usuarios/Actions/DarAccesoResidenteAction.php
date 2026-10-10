<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\PersonaParaCuentaAction;
use App\Modules\Unidades\Actions\VincularCuentaPersonaAction;
use App\Modules\Usuarios\Services\CuentasDePersonas;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: el administrador le da a una persona que ocupa una unidad acceso a la app del
 * residente. Se crea su cuenta con el correo de su ficha (o se reutiliza la que ya tiene),
 * se le asigna el perfil residente y se le envía el enlace para crear su contraseña. El
 * perfil residente no cuenta para el límite de usuarios del plan.
 */
final class DarAccesoResidenteAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PersonaParaCuentaAction $personas,
        private readonly VincularCuentaPersonaAction $vincular,
        private readonly CuentasDePersonas $cuentas,
        private readonly PerfilesUsuario $perfiles,
    ) {}

    /**
     * @return array{persona_id: int, invitacion_enviada: bool}
     */
    public function execute(int $personaId, User $actor): array
    {
        return DB::transaction(function () use ($personaId, $actor): array {
            $condominio = Condominio::query()->findOrFail($this->tenant->require());

            $persona = $this->personas->execute($personaId)
                ?? throw new ApiException('PERSONA_SIN_UNIDAD', 'Solo se da acceso a quien hoy ocupa una unidad (propietario, inquilino o residente).', 422);

            if ($persona['user_id'] !== null) {
                throw new ApiException('PERSONA_YA_TIENE_ACCESO', 'Esta persona ya tiene acceso a la app.', 409);
            }

            if ($persona['email'] === null || $persona['email'] === '') {
                throw new ApiException('PERSONA_SIN_CORREO', 'Esta persona no tiene correo registrado: lo necesita para entrar a la app. Agrégalo en su ficha.', 422);
            }

            $user = $this->cuentas->cuenta($persona, $condominio);
            $this->vincular->execute($personaId, $user->id);
            $this->perfiles->agregar($user, Rol::Residente);

            return [
                'persona_id' => $personaId,
                'invitacion_enviada' => $this->cuentas->invitarSiHaceFalta($user, $condominio, Rol::Residente->etiqueta(), $actor),
            ];
        });
    }
}
