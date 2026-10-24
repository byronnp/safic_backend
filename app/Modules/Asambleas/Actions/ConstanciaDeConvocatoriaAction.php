<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Actions\ConstanciaAnuncioAction;

/** PDF de la constancia de la convocatoria: la constancia de lectura del anuncio con que se convocó. */
final class ConstanciaDeConvocatoriaAction
{
    public function __construct(private readonly ConstanciaAnuncioAction $constancia) {}

    /** @return array{contenido: string, nombre: string} */
    public function execute(int $id): array
    {
        $a = Asamblea::query()->findOrFail($id);
        if ($a->anuncio_id === null) {
            throw new ApiException('ASAMBLEA_SIN_CONVOCAR', 'Esta asamblea todavía no se convocó.', 409);
        }

        return $this->constancia->execute($a->anuncio_id);
    }
}
