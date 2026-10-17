<?php

namespace App\Modules\Comunicacion\Actions;

use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Comunicacion\Models\AnuncioDestinatario;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Constancia de lectura de un anuncio en PDF: qué unidades lo recibieron, cuáles lo leyeron o confirmaron y cuándo, y cuáles siguen
 * pendientes. Sirve de respaldo de la convocatoria a una asamblea.
 */
final class ConstanciaAnuncioAction
{
    public function __construct(private readonly TenantContext $tenant, private readonly Calendario $calendario) {}

    /** @return array{contenido: string, nombre: string} */
    public function execute(int $anuncioId): array
    {
        $a = Anuncio::query()->findOrFail($anuncioId);
        $zona = $this->calendario->zonaHoraria();
        $condominio = Condominio::query()->findOrFail($this->tenant->require());

        $filas = AnuncioDestinatario::query()->where('anuncio_id', $a->id)->get();
        $codigos = Unidad::query()->withTrashed()->whereIn('id', $filas->pluck('unidad_id'))->pluck('codigo', 'id');
        $quien = User::query()->whereIn('id', $filas->pluck('confirmado_por')->merge($filas->pluck('leido_por'))->filter()->unique())->pluck('name', 'id');

        $unidades = $filas->map(fn (AnuncioDestinatario $f): array => [
            'codigo' => $codigos[$f->unidad_id] ?? '—',
            'estado' => $f->confirmado_en !== null ? 'Confirmó' : ($f->leido_en !== null ? 'Leyó' : 'Pendiente'),
            'cuando' => ($f->confirmado_en ?? $f->leido_en)?->setTimezone($zona)->format('d/m/Y H:i'),
            'quien' => $quien[$f->confirmado_por ?? $f->leido_por] ?? null,
        ])->sortBy('codigo', SORT_NATURAL)->values()->all();

        $pdf = Pdf::loadView('anuncios.constancia', [
            'condominio' => ['nombre' => $condominio->nombre, 'ruc' => $condominio->ruc, 'direccion' => $condominio->direccion],
            'anuncio' => ['titulo' => $a->titulo, 'destino' => $a->destino_texto, 'publicado' => $a->created_at->setTimezone($zona)->format('d/m/Y H:i'), 'pide_confirmacion' => $a->requiere_confirmacion],
            'totales' => ['unidades' => count($unidades), 'leyeron' => $filas->whereNotNull('leido_en')->count(), 'confirmaron' => $filas->whereNotNull('confirmado_en')->count()],
            'unidades' => $unidades,
            'emitida' => now($zona)->format('d/m/Y H:i'),
        ]);

        return ['contenido' => $pdf->output(), 'nombre' => 'constancia-'.Str::slug($a->titulo ?: 'anuncio').'.pdf'];
    }
}
