<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Models\Votacion;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del presidente: abrir la votación de un punto. Solo en una asamblea instalada y con una votación a la vez; un punto
 * se vota una sola vez. Si la asamblea se instaló en primera convocatoria y el quórum bajó del mínimo (salieron personas), avisa
 * antes de abrir y pide confirmar. Las elecciones de directiva se votan con candidatos, no con Sí/No.
 */
final class AbrirVotacionAction
{
    public function __construct(
        private readonly CalcularQuorumAction $quorum,
        private readonly ObtenerConfiguracionAction $configuracion,
        private readonly TenantContext $tenant,
    ) {}

    public function execute(User $user, int $puntoId, bool $continuarSinQuorum): Votacion
    {
        $v = DB::transaction(function () use ($user, $puntoId, $continuarSinQuorum): Votacion {
            $punto = AsambleaPunto::query()->findOrFail($puntoId);
            $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($punto->asamblea_id);

            if ($asamblea->estado === Asamblea::EN_VOTACION) {
                throw new ApiException('VOTACION_ABIERTA', 'Hay una votación abierta: ciérrala antes de abrir otra.', 409);
            }
            if ($asamblea->estado !== Asamblea::INSTALADA) {
                throw new ApiException('ASAMBLEA_NO_INSTALADA', 'Solo se vota en una asamblea instalada.', 409);
            }
            if (! $punto->seVota()) {
                throw new ApiException('PUNTO_INFORMATIVO', 'Un punto informativo solo se trata: no se vota.', 422);
            }
            if ($punto->tipo === AsambleaPunto::ELECCION) {
                throw new ApiException('ELECCION_CON_CANDIDATOS', 'La elección de directiva se vota con candidatos, no con Sí o No.', 422);
            }
            if (Votacion::query()->where('punto_id', $punto->id)->exists()) {
                throw new ApiException('PUNTO_YA_VOTADO', 'Este punto ya se votó: una votación cerrada no se repite.', 409);
            }

            $q = $this->quorum->execute($asamblea);
            if ($asamblea->convocatoria === 1 && ! $q['hay_quorum'] && ! $continuarSinQuorum) {
                throw new ApiException('QUORUM_BAJO', 'El quórum bajó del mínimo ('.$q['porcentaje'].' % de las alícuotas): salieron asistentes. Confirma si quieres abrir la votación de todos modos.', 409);
            }

            $v = Votacion::create([
                'asamblea_id' => $asamblea->id, 'punto_id' => $punto->id, 'estado' => Votacion::ABIERTA,
                'secreta' => $this->configuracion->modelo()->voto_secreto, 'mayoria' => $punto->mayoria, 'umbral_pct' => $punto->umbral_pct,
                'base' => $punto->base ?? 'presentes', 'abierta_por' => $user->id, 'abierta_en' => now(),
            ]);
            $asamblea->forceFill(['estado' => Asamblea::EN_VOTACION])->save();

            return $v;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'votacion', $v->id);

        return $v;
    }
}
