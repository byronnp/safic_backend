<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Unidades\Actions\UnidadesDeCuentaAction;

/**
 * Consulta para el residente: los anuncios vigentes que llegaron a sus unidades (los fijados primero, luego los más recientes) y
 * si ya los leyó o confirmó. El estado es de la unidad: si vive en dos, vale «leído» cuando ya los leyó en todas.
 */
final class ListarMisAvisosAction
{
    public function __construct(
        private readonly Calendario $calendario,
        private readonly UnidadesDeCuentaAction $unidades,
        private readonly ArchivosCondominio $archivos,
    ) {}

    /** @return array{no_leidos: int, avisos: list<array<string, mixed>>} */
    public function execute(User $user): array
    {
        $ids = array_column($this->unidades->execute($user->id), 'unidad_id');
        $hoy = $this->calendario->hoy();
        $filas = AnuncioDestinatario::query()->whereIn('unidad_id', $ids)->get()->groupBy('anuncio_id');

        $anuncios = Anuncio::query()->whereIn('id', $filas->keys())
            ->where(fn ($q) => $q->whereNull('expira_en')->orWhereDate('expira_en', '>=', $hoy))
            ->orderByDesc('fijado')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        $avisos = $anuncios->map(function (Anuncio $a) use ($filas): array {
            $mias = $filas->get($a->id);

            return [
                'id' => $a->id, 'titulo' => $a->titulo, 'mensaje' => $a->mensaje, 'destino' => $a->destino_texto,
                'publicado_en' => $a->created_at->setTimezone($this->calendario->zonaHoraria())->toDateString(),
                'expira_en' => $a->expira_en?->toDateString(), 'fijado' => $a->fijado, 'requiere_confirmacion' => $a->requiere_confirmacion,
                'leido' => $mias->every(fn (AnuncioDestinatario $f): bool => $f->leido_en !== null),
                'confirmado' => $mias->every(fn (AnuncioDestinatario $f): bool => $f->confirmado_en !== null),
                'adjunto_nombre' => $a->adjunto_nombre,
                'adjunto_url' => $a->adjunto_ruta !== null && $this->archivos->existe($a->adjunto_ruta) ? $this->archivos->urlTemporal($a->adjunto_ruta) : null,
            ];
        })->values();

        return ['no_leidos' => $avisos->where('leido', false)->count(), 'avisos' => $avisos->all()];
    }
}
