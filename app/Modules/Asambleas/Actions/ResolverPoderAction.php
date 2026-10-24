<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Finanzas\Actions\EstadoDeUnidadParaAreasAction;
use App\Modules\Unidades\Models\Ocupante;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración: validar o rechazar un poder pendiente (Art. 40). Al validarlo la unidad queda asignada al
 * representante. Quien debe cuotas no puede representar a nadie mientras deba (Art. 39 y Decreto 462).
 */
final class ResolverPoderAction
{
    public function __construct(private readonly EstadoDeUnidadParaAreasAction $estado) {}

    public function validar(User $user, int $id): AsambleaPoder
    {
        return DB::transaction(function () use ($user, $id): AsambleaPoder {
            $poder = $this->pendiente($id);

            if ($poder->representante_persona_id !== null) {
                $suyas = Ocupante::query()->vigentes()->where('relacion', 'propietario')->where('persona_id', $poder->representante_persona_id)->pluck('unidad_id')->unique()->values()->all();
                if (in_array(false, $this->estado->alDia($suyas), true)) {
                    throw new ApiException('REPRESENTANTE_EN_MORA', 'El representante tiene cuotas vencidas: no puede representar a otro propietario mientras deba.', 422);
                }
            }

            $poder->forceFill(['estado' => AsambleaPoder::VALIDADO, 'validado_por' => $user->id, 'validado_en' => now(), 'motivo_rechazo' => null])->save();

            return $poder;
        });
    }

    public function rechazar(User $user, int $id, string $motivo): AsambleaPoder
    {
        return DB::transaction(function () use ($user, $id, $motivo): AsambleaPoder {
            $poder = $this->pendiente($id);
            $poder->forceFill(['estado' => AsambleaPoder::RECHAZADO, 'validado_por' => $user->id, 'validado_en' => now(), 'motivo_rechazo' => $motivo])->save();

            return $poder;
        });
    }

    private function pendiente(int $id): AsambleaPoder
    {
        $poder = AsambleaPoder::query()->lockForUpdate()->findOrFail($id);
        if ($poder->estado !== AsambleaPoder::PENDIENTE) {
            throw new ApiException('PODER_NO_PENDIENTE', 'Este poder ya se resolvió.', 409);
        }
        if (! Asamblea::query()->findOrFail($poder->asamblea_id)->estaConvocada()) {
            throw new ApiException('PODERES_CERRADOS', 'La asamblea ya se instaló o se canceló: no se resuelven más poderes.', 409);
        }

        return $poder;
    }
}
