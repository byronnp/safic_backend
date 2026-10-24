<?php

namespace App\Modules\Asambleas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Unidades\Models\Unidad;

/**
 * Cómo va la convocatoria: cuántas unidades la recibieron, la leyeron y confirmaron, y cuáles siguen pendientes.
 * Es el respaldo de que se convocó a tiempo; el PDF sale de la constancia del anuncio.
 */
final class VerConvocatoriaAction
{
    public function __construct(private readonly Calendario $calendario) {}

    /** @return array<string, mixed> */
    public function execute(int $id): array
    {
        $a = Asamblea::query()->findOrFail($id);
        if ($a->anuncio_id === null) {
            throw new ApiException('ASAMBLEA_SIN_CONVOCAR', 'Esta asamblea todavía no se convocó.', 409);
        }
        $anuncio = Anuncio::query()->findOrFail($a->anuncio_id);
        $zona = $this->calendario->zonaHoraria();

        $filas = AnuncioDestinatario::query()->where('anuncio_id', $anuncio->id)->get();
        $codigos = Unidad::query()->withTrashed()->whereIn('id', $filas->pluck('unidad_id'))->pluck('codigo', 'id');
        $total = $filas->count();
        $confirmaron = $filas->whereNotNull('confirmado_en')->count();

        $pendientes = $filas->whereNull('confirmado_en')->map(fn (AnuncioDestinatario $f): array => [
            'unidad_id' => (int) $f->unidad_id, 'codigo' => (string) ($codigos[$f->unidad_id] ?? '—'), 'leyo' => $f->leido_en !== null,
        ])->sortBy('codigo', SORT_NATURAL)->values()->all();

        return [
            'anuncio_id' => $anuncio->id,
            'publicada_en' => $anuncio->created_at->setTimezone($zona)->toIso8601String(),
            'unidades' => $total,
            'leyeron' => $filas->whereNotNull('leido_en')->count(),
            'confirmaron' => $confirmaron,
            'porcentaje_confirmaron' => $total === 0 ? 0 : round($confirmaron * 100 / $total, 1),
            'pendientes' => $pendientes,
            'recordatorios' => ['tres_dias' => $a->recordada_3d_en?->toIso8601String(), 'dos_horas' => $a->recordada_2h_en?->toIso8601String()],
        ];
    }
}
