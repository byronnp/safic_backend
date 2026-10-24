<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Actions\ReenviarAnuncioAction;

/** Reenvía la convocatoria por correo a las unidades que aún no confirmaron. Devuelve a cuántas se les avisó. */
final class ReenviarConvocatoriaAction
{
    public function __construct(private readonly ReenviarAnuncioAction $reenviar) {}

    public function execute(int $id): int
    {
        $a = Asamblea::query()->findOrFail($id);
        if ($a->anuncio_id === null || ! $a->estaConvocada()) {
            throw new ApiException('ASAMBLEA_SIN_CONVOCATORIA_VIGENTE', 'Esta asamblea no tiene una convocatoria vigente para reenviar.', 409);
        }

        return $this->reenviar->execute($a->anuncio_id);
    }
}
