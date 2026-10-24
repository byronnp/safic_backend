<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Realtime\EnVivo;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\Votacion;
use App\Modules\Asambleas\Services\ResultadoDeVotacion;
use Illuminate\Support\Facades\DB;

/** Caso de uso del presidente: cerrar la votación abierta. Calcula el resultado por alícuota y la mayoría del punto, y devuelve la asamblea a «instalada». */
final class CerrarVotacionAction
{
    public function __construct(private readonly ResultadoDeVotacion $resultado, private readonly TenantContext $tenant) {}

    public function execute(User $user, int $id): Votacion
    {
        $v = DB::transaction(function () use ($user, $id): Votacion {
            $v = Votacion::query()->lockForUpdate()->findOrFail($id);
            if (! $v->estaAbierta()) {
                throw new ApiException('VOTACION_CERRADA', 'Esta votación ya se cerró.', 409);
            }

            if ($v->esEleccion()) {
                $e = $this->resultado->calcularEleccion($v);
                $v->forceFill([
                    'estado' => Votacion::CERRADA, 'cerrada_por' => $user->id, 'cerrada_en' => now(), 'base_alicuota' => $e['base_alicuota'],
                    'ganador_candidato_id' => $e['ganador_candidato_id'], 'resultado' => $e['resultado'],
                ])->save();
                Asamblea::query()->whereKey($v->asamblea_id)->where('estado', Asamblea::EN_VOTACION)->update(['estado' => Asamblea::INSTALADA]);

                return $v;
            }

            $r = $this->resultado->calcular($v);
            $v->forceFill([
                'estado' => Votacion::CERRADA, 'cerrada_por' => $user->id, 'cerrada_en' => now(),
                'base_alicuota' => $r['base_alicuota'], 'alicuota_si' => $r['alicuota_si'], 'alicuota_no' => $r['alicuota_no'], 'alicuota_abstencion' => $r['alicuota_abstencion'],
                'votos_si' => $r['votos_si'], 'votos_no' => $r['votos_no'], 'votos_abstencion' => $r['votos_abstencion'], 'resultado' => $r['resultado'],
            ])->save();
            Asamblea::query()->whereKey($v->asamblea_id)->where('estado', Asamblea::EN_VOTACION)->update(['estado' => Asamblea::INSTALADA]);

            return $v;
        });
        EnVivo::aAsambleas($this->tenant->require(), 'votacion', $v->id);

        return $v;
    }
}
