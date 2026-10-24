<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Permissions\Permiso;
use App\Models\User;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: retirar un poder vigente (pendiente o validado) hasta que se instala la asamblea, para cambiar de representante o
 * porque el propietario va a asistir. Lo hace la administración o un propietario de esa unidad.
 */
final class RevocarPoderAction
{
    public function __construct(private readonly UnidadesDeCuentaAction $unidadesDeCuenta) {}

    public function execute(User $user, int $id): AsambleaPoder
    {
        return DB::transaction(function () use ($user, $id): AsambleaPoder {
            $poder = AsambleaPoder::query()->lockForUpdate()->findOrFail($id);

            // La administración y quien registra la asistencia (el propietario llegó en persona) pueden retirar cualquier poder
            if (! $user->can(Permiso::AsambleasPreparar->value) && ! $user->can(Permiso::AsambleasRegistrar->value)) {
                $propietariaDe = collect($this->unidadesDeCuenta->execute($user->id))->where('relacion', 'propietario')->pluck('unidad_id')->all();
                if (! in_array($poder->unidad_id, $propietariaDe, true)) {
                    throw new ApiException('NO_ES_PROPIETARIO', 'Solo el propietario de la unidad puede retirar su poder.', 403);
                }
            }
            if (! $poder->estaVigente()) {
                throw new ApiException('PODER_NO_VIGENTE', 'Este poder ya no está vigente.', 409);
            }
            if (! Asamblea::query()->findOrFail($poder->asamblea_id)->estaConvocada()) {
                throw new ApiException('PODERES_CERRADOS', 'La asamblea ya se instaló o se canceló: el poder ya no se puede retirar.', 409);
            }

            $poder->forceFill(['estado' => AsambleaPoder::REVOCADO, 'revocado_en' => now()])->save();

            return $poder;
        });
    }
}
