<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Modules\Amenidades\Models\AmenidadFoto;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: quitar una foto de una amenidad. Las demás se vuelven a numerar (si era la
 * portada, la siguiente pasa a serlo) y el archivo se borra de S3 al confirmarse el cambio.
 */
final class QuitarFotoAmenidadAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    public function execute(int $amenidadId, int $fotoId): void
    {
        $ruta = DB::transaction(function () use ($amenidadId, $fotoId): string {
            $amenidad = CondominioAmenidad::query()->lockForUpdate()->findOrFail($amenidadId);
            $foto = AmenidadFoto::query()->where('amenidad_id', $amenidad->id)->findOrFail($fotoId);
            $foto->delete();

            AmenidadFoto::query()->where('amenidad_id', $amenidad->id)->orderBy('orden')->orderBy('id')->get()
                ->each(fn (AmenidadFoto $f, int $i) => $f->update(['orden' => $i + 1]));

            return $foto->ruta;
        });

        $this->archivos->eliminar($ruta);
    }
}
