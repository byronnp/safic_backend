<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Comunicacion\Models\Incidencia;
use App\Modules\Comunicacion\Models\IncidenciaComentario;
use App\Modules\Comunicacion\Models\IncidenciaFoto;
use App\Modules\Unidades\Models\Unidad;

/**
 * Cómo se ve una incidencia por dentro: sus datos, fotos (con enlace temporal) y comentarios. La administración ve también las notas
 * internas; el residente no.
 */
final class DetalleIncidenciaAction
{
    public function __construct(private readonly Calendario $calendario, private readonly ArchivosCondominio $archivos) {}

    /** @return array<string, mixed> */
    public function execute(Incidencia $i, bool $conInternas): array
    {
        $zona = $this->calendario->zonaHoraria();
        $comentarios = IncidenciaComentario::query()->where('incidencia_id', $i->id)->when(! $conInternas, fn ($q) => $q->where('interna', false))->orderBy('created_at')->orderBy('id')->get();
        $autores = User::query()->whereIn('id', $comentarios->pluck('user_id')->push($i->reportado_por)->filter()->unique())->pluck('name', 'id');

        return [
            'id' => $i->id, 'numero' => $i->numero, 'titulo' => $i->titulo, 'descripcion' => $i->descripcion, 'categoria' => $i->categoria,
            'prioridad' => $i->prioridad, 'estado' => $i->estado,
            'unidad' => $i->unidad_id === null ? null : Unidad::query()->withTrashed()->whereKey($i->unidad_id)->value('codigo'),
            'autor' => $autores[$i->reportado_por] ?? null,
            'creada_en' => $i->created_at->setTimezone($zona)->toIso8601String(),
            'resuelta_en' => $i->resuelta_en?->setTimezone($zona)->toIso8601String(),
            'cierre' => $i->cierre,
            'fotos' => IncidenciaFoto::query()->where('incidencia_id', $i->id)->orderBy('orden')->get()
                ->map(fn (IncidenciaFoto $f): array => ['id' => $f->id, 'url' => $this->archivos->existe($f->ruta) ? $this->archivos->urlTemporal($f->ruta) : null])->all(),
            'comentarios' => $comentarios->map(fn (IncidenciaComentario $c): array => [
                'id' => $c->id, 'autor' => $c->de_administracion ? 'Administración' : ($autores[$c->user_id] ?? 'Residente'),
                'de_administracion' => $c->de_administracion, 'interna' => $c->interna, 'texto' => $c->texto,
                'creado_en' => $c->created_at->setTimezone($zona)->toIso8601String(),
            ])->all(),
        ];
    }
}
