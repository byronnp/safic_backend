<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Modules\Asambleas\Models\Asamblea;
use Illuminate\Support\Facades\DB;

/** Caso de uso: descartar un borrador (con sus puntos y adjuntos). Una asamblea ya convocada se cancela con motivo, no se borra. */
final class EliminarAsambleaAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    public function execute(int $id): void
    {
        $rutas = DB::transaction(function () use ($id): array {
            $a = Asamblea::query()->lockForUpdate()->findOrFail($id);
            if (! $a->esBorrador()) {
                throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se descarta un borrador: una asamblea convocada se cancela con motivo.', 409);
            }
            $rutas = $a->puntos()->whereNotNull('adjunto_ruta')->pluck('adjunto_ruta')->all();
            $a->delete();

            return $rutas;
        });

        foreach ($rutas as $ruta) {
            $this->archivos->eliminar($ruta);
        }
    }
}
