<?php

namespace App\Modules\Garita\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Garita\Models\AccesoGarita;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del guardia: registrar la salida de quien está dentro.
 */
final class RegistrarSalidaAction
{
    public function execute(User $guardia, int $id): AccesoGarita
    {
        return DB::transaction(function () use ($guardia, $id): AccesoGarita {
            $a = AccesoGarita::query()->lockForUpdate()->findOrFail($id);
            if ($a->estado !== AccesoGarita::DENTRO) {
                throw new ApiException('YA_SALIO', 'Esta persona ya tiene su salida registrada.', 409);
            }
            $a->forceFill(['estado' => AccesoGarita::SALIO, 'salida_en' => now(), 'salida_por' => $guardia->id])->save();

            return $a;
        });
    }
}
