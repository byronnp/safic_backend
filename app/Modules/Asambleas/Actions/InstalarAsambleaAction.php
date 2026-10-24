<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Rol;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Services\PadronDeLaAsamblea;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del presidente: instalar la asamblea (Art. 39). En primera convocatoria exige más del 50 % de las alícuotas; en segunda,
 * que hayan pasado 60 minutos de la hora de la convocatoria y que haya al menos un asistente: se instala con los presentes. Al
 * instalar se congela la foto de unidades, alícuotas, propietarios y mora, y se guarda el quórum que había (va en el acta).
 *
 * El vicepresidente subroga al presidente: instala declarando que el presidente está ausente y el acta dirá «en subrogación».
 */
final class InstalarAsambleaAction
{
    public function __construct(
        private readonly CalcularQuorumAction $quorum,
        private readonly PadronDeLaAsamblea $padron,
        private readonly ObtenerConfiguracionAction $configuracion,
        private readonly TenantContext $tenant,
    ) {}

    /** @param array{convocatoria: int, presidente_ausente?: bool} $d */
    public function execute(User $user, int $id, array $d): Asamblea
    {
        $a = DB::transaction(function () use ($user, $id, $d): Asamblea {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($id);
            if ($a->estado !== Asamblea::EN_REGISTRO) {
                throw new ApiException('INSTALACION_NO_DISPONIBLE', $a->instalada_en !== null ? 'Esta asamblea ya se instaló.' : 'Abre el registro de asistencia antes de instalar la asamblea.', 409);
            }

            $subroga = $user->hasRole(Rol::Vicepresidente->value) && ! $user->hasRole(Rol::Presidente->value);
            if ($subroga && ! ($d['presidente_ausente'] ?? false)) {
                throw new ApiException('PRESIDENTE_AUSENTE_REQUERIDO', 'Solo el vicepresidente instala si el presidente está ausente: confírmalo para instalar en subrogación.', 422);
            }

            $q = $this->quorum->execute($a);
            if ($d['convocatoria'] === 1 && ! $q['hay_quorum']) {
                throw new ApiException('SIN_QUORUM', 'En primera convocatoria se necesita más del 50 % de las alícuotas presentes.', 422);
            }
            if ($d['convocatoria'] === 2) {
                if (! $q['segunda_convocatoria']['abierta']) {
                    throw new ApiException('SEGUNDA_CONVOCATORIA_NO_ABIERTA', "La segunda convocatoria se abre a las {$q['segunda_convocatoria']['desde']}, 60 minutos después de la primera.", 422);
                }
                if ($q['unidades_presentes'] === 0) {
                    throw new ApiException('SIN_ASISTENTES', 'No hay ninguna unidad presente: no se puede instalar.', 422);
                }
            }

            // La foto se toma con las reglas de hoy; el quórum que se guarda es el que había en este instante
            $this->padron->congelar($a);
            $a->forceFill([
                'estado' => Asamblea::INSTALADA, 'instalada_en' => now(), 'instalada_por' => $user->id, 'convocatoria' => $d['convocatoria'],
                'en_subrogacion' => $subroga, 'mora_cuenta_para_quorum' => $this->configuracion->modelo()->mora_cuenta_para_quorum,
                'quorum_alicuota_presente' => $q['alicuota_presente'], 'quorum_alicuota_total' => $q['alicuota_total'],
            ])->save();

            return $a;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'asambleas', $a->id);

        return $a;
    }
}
