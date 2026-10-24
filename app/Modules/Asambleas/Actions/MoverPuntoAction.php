<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use Illuminate\Support\Facades\DB;

/** Caso de uso: subir o bajar un punto en el orden del día de un borrador (intercambia lugar con su vecino). */
final class MoverPuntoAction
{
    public function execute(int $asambleaId, int $puntoId, string $direccion): void
    {
        DB::transaction(function () use ($asambleaId, $puntoId, $direccion): void {
            $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
            if (! $asamblea->esBorrador()) {
                throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se edita el orden del día de una asamblea en borrador: esta ya se convocó.', 409);
            }
            $punto = AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->findOrFail($puntoId);
            $vecino = AsambleaPunto::query()->where('asamblea_id', $asamblea->id)
                ->where('orden', $direccion === 'sube' ? $punto->orden - 1 : $punto->orden + 1)->first();
            if ($vecino === null) {
                return; // ya está en el extremo
            }

            // El orden es único por asamblea: se pasa por un lugar libre (0) para intercambiar sin chocar
            [$a, $b] = [$punto->orden, $vecino->orden];
            $punto->forceFill(['orden' => 0])->save();
            $vecino->forceFill(['orden' => $a])->save();
            $punto->forceFill(['orden' => $b])->save();
        });
    }
}
