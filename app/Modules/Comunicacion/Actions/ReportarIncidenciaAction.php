<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\SequenceService;
use App\Models\User;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Comunicacion\Models\IncidenciaFoto;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: un residente (o el guardia) reporta un problema, con hasta 3 fotos. La unidad es opcional (sin ella es un problema
 * de las áreas comunes) y, si se indica, debe ser una en la que vive quien reporta.
 */
final class ReportarIncidenciaAction
{
    public function __construct(
        private readonly SequenceService $secuencias,
        private readonly ArchivosCondominio $archivos,
        private readonly UnidadesDeCuentaAction $unidades,
    ) {}

    /** @param list<UploadedFile> $fotos */
    public function execute(User $user, string $titulo, string $descripcion, string $categoria, ?int $unidadId, array $fotos): Incidencia
    {
        if ($unidadId !== null && ! in_array($unidadId, array_column($this->unidades->execute($user->id), 'unidad_id'), true)) {
            throw new ApiException('UNIDAD_NO_ES_SUYA', 'Solo puedes reportar desde una unidad en la que vives.', 403);
        }
        if (count($fotos) > Incidencia::MAXIMO_FOTOS) {
            throw new ApiException('DEMASIADAS_FOTOS', 'Puedes adjuntar hasta '.Incidencia::MAXIMO_FOTOS.' fotos.', 422);
        }

        $rutas = array_map(fn (UploadedFile $f): string => $this->archivos->guardar($f, 'incidencias'), $fotos);

        try {
            return DB::transaction(function () use ($user, $titulo, $descripcion, $categoria, $unidadId, $rutas): Incidencia {
                $i = Incidencia::create([
                    'numero' => $this->secuencias->siguiente('incidencias'), 'unidad_id' => $unidadId, 'reportado_por' => $user->id,
                    'titulo' => $titulo, 'descripcion' => $descripcion, 'categoria' => $categoria, 'prioridad' => 'media', 'estado' => Incidencia::ABIERTO,
                ]);
                foreach ($rutas as $orden => $ruta) {
                    IncidenciaFoto::create(['incidencia_id' => $i->id, 'ruta' => $ruta, 'orden' => $orden + 1]);
                }

                return $i;
            });
        } catch (\Throwable $e) {
            foreach ($rutas as $ruta) {
                $this->archivos->eliminar($ruta);
            }

            throw $e;
        }
    }
}
