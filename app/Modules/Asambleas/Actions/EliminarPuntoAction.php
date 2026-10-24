<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use Illuminate\Support\Facades\DB;

/** Caso de uso: quitar un punto del orden del día de un borrador; los que siguen suben un lugar. */
final class EliminarPuntoAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    public function execute(int $asambleaId, int $puntoId): void
    {
        $ruta = DB::transaction(function () use ($asambleaId, $puntoId): ?string {
            $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
            if (! $asamblea->esBorrador()) {
                throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se edita el orden del día de una asamblea en borrador: esta ya se convocó.', 409);
            }
            $punto = AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->findOrFail($puntoId);
            $ruta = $punto->adjunto_ruta;
            $orden = $punto->orden;
            $punto->delete();
            // Uno a uno y en orden: cada punto ocupa el lugar que acaba de quedar libre
            foreach (AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->where('orden', '>', $orden)->orderBy('orden')->get() as $siguiente) {
                $siguiente->forceFill(['orden' => $siguiente->orden - 1])->save();
            }

            return $ruta;
        });

        if ($ruta !== null) {
            $this->archivos->eliminar($ruta);
        }
    }
}
