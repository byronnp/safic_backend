<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Subscriptions\LimiteUsuarios;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Actions\PropietariosVigentesAction;
use App\Modules\Usuarios\Models\CargoDirectiva;
use App\Modules\Usuarios\Services\CuentasDePersonas;
use App\Modules\Usuarios\Services\PerfilesUsuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: nombrar a una persona en un cargo de la directiva. Si el cargo tenía
 * titular, su periodo se cierra hoy y pierde ese cargo (conserva sus demás perfiles,
 * como residente). Un cargo, una persona; una persona, un cargo. Solo propietarios.
 * La persona necesita cuenta para ejercer el cargo: si no la tiene se crea y se invita.
 */
final class AsignarCargoAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly PropietariosVigentesAction $propietarios,
        private readonly PerfilesUsuario $perfiles,
        private readonly LimiteUsuarios $limite,
        private readonly CuentasDePersonas $cuentas,
    ) {}

    public function execute(Rol $cargo, int $personaId, string $acta, string $periodoHasta, User $actor): CargoDirectiva
    {
        return DB::transaction(function () use ($cargo, $personaId, $acta, $periodoHasta, $actor): CargoDirectiva {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($this->tenant->require());
            $hoy = $this->calendario->hoy();

            $persona = $this->propietarios->execute([$personaId])[0]
                ?? throw new ApiException('NO_ES_PROPIETARIO', 'Solo los propietarios pueden ocupar un cargo de la directiva.', 422);

            if ($persona['email'] === null || $persona['email'] === '') {
                throw new ApiException('PERSONA_SIN_CORREO', 'Esta persona no tiene correo registrado: lo necesita para entrar al sistema. Agrégalo en su ficha.', 422);
            }

            $abiertos = CargoDirectiva::query()->whereNull('cerrado_en')->lockForUpdate()->get();

            $suyo = $abiertos->firstWhere('persona_id', $personaId);
            if ($suyo !== null) {
                $mismo = $suyo->cargo === $cargo->value;
                throw new ApiException(
                    $mismo ? 'YA_ES_TITULAR' : 'PERSONA_CON_CARGO',
                    $mismo ? 'Esa persona ya ocupa este cargo.' : 'Esa persona ya es '.Rol::from($suyo->cargo)->etiqueta().': una persona ocupa un solo cargo.',
                    409,
                );
            }

            // Cierra el periodo del titular actual y le quita el cargo (no sus demás perfiles)
            $actual = $abiertos->firstWhere('cargo', $cargo->value);
            if ($actual !== null) {
                $actual->update(['cerrado_en' => $hoy]);
                $anterior = $actual->user_id === null ? null : User::query()->find($actual->user_id);
                if ($anterior !== null) {
                    $this->perfiles->quitar($anterior, $cargo);
                }
            }

            $user = $this->cuentas->cuenta($persona, $condominio);

            if ($cargo->cuentaParaCupo() && ! $this->limite->consume($user->id)) {
                $this->limite->asegurarCupo();
            }

            $this->perfiles->agregar($user, $cargo);

            $this->cuentas->invitarSiHaceFalta($user, $condominio, $cargo->etiqueta(), $actor);

            return CargoDirectiva::create([
                'cargo' => $cargo->value,
                'persona_id' => $personaId,
                'user_id' => $user->id,
                'periodo_inicio' => $hoy,
                'periodo_fin' => Carbon::parse($periodoHasta)->toDateString(),
                'acta' => $acta,
            ]);
        });
    }
}
