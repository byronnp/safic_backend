<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Caso de uso: adjuntar (o quitar) el informe de un punto del orden del día (un PDF). El archivo anterior se borra. */
final class AdjuntoDePuntoAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    public function subir(int $asambleaId, int $puntoId, UploadedFile $archivo): AsambleaPunto
    {
        $ruta = $this->archivos->guardar($archivo, 'asambleas');
        try {
            [$punto, $anterior] = DB::transaction(function () use ($asambleaId, $puntoId, $ruta, $archivo): array {
                $punto = $this->punto($asambleaId, $puntoId);
                $anterior = $punto->adjunto_ruta;
                $punto->forceFill(['adjunto_ruta' => $ruta, 'adjunto_nombre' => Str::limit($archivo->getClientOriginalName(), 120, '')])->save();

                return [$punto, $anterior];
            });
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }

        if ($anterior !== null) {
            $this->archivos->eliminar($anterior);
        }

        return $punto;
    }

    public function quitar(int $asambleaId, int $puntoId): AsambleaPunto
    {
        [$punto, $anterior] = DB::transaction(function () use ($asambleaId, $puntoId): array {
            $punto = $this->punto($asambleaId, $puntoId);
            $anterior = $punto->adjunto_ruta;
            $punto->forceFill(['adjunto_ruta' => null, 'adjunto_nombre' => null])->save();

            return [$punto, $anterior];
        });

        if ($anterior !== null) {
            $this->archivos->eliminar($anterior);
        }

        return $punto;
    }

    private function punto(int $asambleaId, int $puntoId): AsambleaPunto
    {
        $asamblea = Asamblea::query()->lockForUpdate()->findOrFail($asambleaId);
        if (! $asamblea->esBorrador()) {
            throw new ApiException('ASAMBLEA_NO_EDITABLE', 'Solo se edita el orden del día de una asamblea en borrador: esta ya se convocó.', 409);
        }

        return AsambleaPunto::query()->where('asamblea_id', $asamblea->id)->lockForUpdate()->findOrFail($puntoId);
    }
}
