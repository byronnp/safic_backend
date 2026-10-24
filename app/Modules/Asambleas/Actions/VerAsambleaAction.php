<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Services\AnticipacionDeConvocatoria;

/**
 * Consulta de la administración: una asamblea con su orden del día, la anticipación de la convocatoria (8 días hábiles), el padrón de
 * hoy y qué falta para poder convocarla.
 */
final class VerAsambleaAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly AnticipacionDeConvocatoria $anticipacion,
        private readonly VerPadronAction $padron,
        private readonly ArchivosCondominio $archivos,
    ) {}

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $a = Asamblea::query()->with('puntos')->findOrFail($id);
        $anticipacion = $this->anticipacion->de($a->fecha_hora);
        $faltan = $this->faltaParaConvocar($a, $anticipacion);

        return $this->resumen($a) + [
            'segunda_convocatoria' => $anticipacion['segunda_convocatoria'],
            'anticipacion' => $anticipacion,
            'puntos' => $a->puntos->map(fn (AsambleaPunto $p): array => $this->punto($p))->all(),
            'padron' => $this->padron->execute(),
            'falta_para_convocar' => $faltan,
            'puede_convocar' => $a->esBorrador() && $faltan === [],
            'puede_cancelar' => $a->estaConvocada(),
            'convocada_en' => $a->convocada_en?->setTimezone($this->calendario->zonaHoraria())->toIso8601String(),
            'motivo_cancelacion' => $a->motivo_cancelacion,
        ];
    }

    /**
     * @param  array{cumple: bool, ultimo_dia: string}  $anticipacion
     * @return list<string>
     */
    private function faltaParaConvocar(Asamblea $a, array $anticipacion): array
    {
        if (! $a->esBorrador()) {
            return [];
        }
        $faltan = [];
        if ($a->puntos->isEmpty()) {
            $faltan[] = 'Agrega al menos un punto al orden del día.';
        }
        if (! $anticipacion['cumple']) {
            $faltan[] = 'Ya no hay 8 días hábiles de anticipación: cambia la fecha de la asamblea (se debió convocar a más tardar el '.$anticipacion['ultimo_dia'].').';
        }

        return $faltan;
    }

    /** @return array<string, mixed> */
    public function resumen(Asamblea $a): array
    {
        return [
            'id' => $a->id, 'titulo' => $a->titulo, 'tipo' => $a->tipo, 'modalidad' => $a->modalidad, 'estado' => $a->estado,
            'fecha_hora' => $a->fecha_hora->setTimezone($this->calendario->zonaHoraria())->format('Y-m-d\TH:i'),
            'lugar' => $a->lugar, 'enlace_video' => $a->enlace_video,
        ];
    }

    /** @return array<string, mixed> */
    public function punto(AsambleaPunto $p): array
    {
        return [
            'id' => $p->id, 'orden' => $p->orden, 'titulo' => $p->titulo, 'descripcion' => $p->descripcion, 'tipo' => $p->tipo,
            'mayoria' => $p->mayoria, 'umbral_pct' => $p->umbral_pct === null ? null : (string) $p->umbral_pct, 'base' => $p->base,
            'adjunto_nombre' => $p->adjunto_nombre,
            'adjunto_url' => $p->adjunto_ruta !== null && $this->archivos->existe($p->adjunto_ruta) ? $this->archivos->urlTemporal($p->adjunto_ruta) : null,
        ];
    }
}
