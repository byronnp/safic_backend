<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Tenancy\Calendario;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;

/**
 * Consulta de la administración: los anuncios publicados (los fijados primero, luego los más recientes) con cuántas unidades los
 * leyeron y, si pedían confirmación, cuántas la dieron.
 */
final class ListarAnunciosAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** @return list<array<string, mixed>> */
    public function execute(): array
    {
        $hoy = $this->calendario->hoy();
        $anuncios = Anuncio::query()->orderByDesc('fijado')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        $conteos = AnuncioDestinatario::query()->whereIn('anuncio_id', $anuncios->pluck('id'))
            ->selectRaw('anuncio_id, count(*) as total, count(leido_en) as leidas, count(confirmado_en) as confirmadas')->groupBy('anuncio_id')->get()->keyBy('anuncio_id');

        return $anuncios->map(function (Anuncio $a) use ($conteos, $hoy): array {
            $c = $conteos->get($a->id);
            $total = (int) ($c->total ?? 0);
            $leidas = (int) ($c->leidas ?? 0);

            return [
                'id' => $a->id, 'titulo' => $a->titulo, 'destino' => $a->destino_texto,
                'publicado_en' => $a->created_at->setTimezone($this->calendario->zonaHoraria())->toDateString(),
                'expira_en' => $a->expira_en?->toDateString(), 'vigente' => $a->expira_en === null || $a->expira_en->toDateString() >= $hoy,
                'fijado' => $a->fijado, 'requiere_confirmacion' => $a->requiere_confirmacion,
                'unidades' => $total, 'leidas' => $leidas, 'confirmadas' => (int) ($c->confirmadas ?? 0),
                'porcentaje_leido' => $total === 0 ? 0 : (int) round($leidas * 100 / $total),
                'tiene_adjunto' => $a->adjunto_ruta !== null,
            ];
        })->values()->all();
    }
}
