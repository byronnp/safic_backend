<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Modules\Amenidades\Models\AmenidadFoto;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: agregar una foto a una amenidad. Va al final (la primera es la portada) y no
 * pasa de AmenidadFoto::MAXIMO por amenidad. Si la base no guarda la ruta, el archivo no
 * queda huérfano en S3.
 */
final class SubirFotoAmenidadAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    public function execute(int $amenidadId, UploadedFile $archivo): AmenidadFoto
    {
        $ruta = $this->archivos->guardar($archivo, 'amenidades');

        try {
            return DB::transaction(function () use ($amenidadId, $ruta): AmenidadFoto {
                // Bloquea la amenidad: dos subidas a la vez no se pasan del máximo
                $amenidad = CondominioAmenidad::query()->lockForUpdate()->findOrFail($amenidadId);
                $actuales = AmenidadFoto::query()->where('amenidad_id', $amenidad->id)->count();

                if ($actuales >= AmenidadFoto::MAXIMO) {
                    throw new ApiException('LIMITE_FOTOS', 'Una amenidad tiene hasta '.AmenidadFoto::MAXIMO.' fotos. Quita una para subir otra.', 422);
                }

                return AmenidadFoto::create([
                    'amenidad_id' => $amenidad->id,
                    'ruta' => $ruta,
                    'orden' => ((int) AmenidadFoto::query()->where('amenidad_id', $amenidad->id)->max('orden')) + 1,
                ]);
            });
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }
    }
}
