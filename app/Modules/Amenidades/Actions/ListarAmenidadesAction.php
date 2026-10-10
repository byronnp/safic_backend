<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;

/**
 * Consulta pública del módulo Amenidades: las amenidades del condominio activo con su
 * estado (disponible, en mantenimiento o inactiva) y los datos del catálogo de donde salieron.
 */
final class ListarAmenidadesAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly Calendario $calendario,
        private readonly ArchivosCondominio $archivos,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        $this->tenant->require();

        $amenidades = CondominioAmenidad::query()->with('fotos')->orderBy('nombre')->get();
        $catalogo = AmenidadCatalogo::query()
            ->whereIn('id', $amenidades->pluck('amenidad_catalogo_id')->filter()->all())
            ->get()
            ->keyBy('id');
        $hoy = $this->calendario->hoy();

        return $amenidades->map(fn (CondominioAmenidad $a): array => $this->item($a, $catalogo->get($a->amenidad_catalogo_id), $hoy))->all();
    }

    public function uno(int $id): ?array
    {
        return collect($this->execute())->firstWhere('id', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(CondominioAmenidad $a, ?AmenidadCatalogo $tipo, string $hoy): array
    {
        $enMantenimiento = $a->mantenimiento_hasta !== null && $a->mantenimiento_hasta->toDateString() >= $hoy;

        return [
            'id' => $a->id,
            'nombre' => $a->nombre,
            'origen' => $a->amenidad_catalogo_id === null ? 'propia' : 'catalogo',
            // El tipo del catálogo ("Área BBQ") o null si es propia del condominio
            'tipo' => $tipo?->nombre,
            'categoria' => $a->categoria ?? $tipo?->categoria,
            'cantidad' => $a->cantidad,
            'ubicacion' => $a->ubicacion,
            'reservable' => $a->reservable,
            'esencial' => $a->esencial,
            'requiere_aprobacion' => $a->requiere_aprobacion,
            'capacidad' => $tipo?->capacidad,
            'duracion_maxima_min' => $tipo?->duracion_maxima_min,
            'estado' => match (true) {
                ! $a->activa => 'inactiva',
                $enMantenimiento => 'mantenimiento',
                default => 'disponible',
            },
            'mantenimiento_hasta' => $enMantenimiento ? $a->mantenimiento_hasta->toDateString() : null,
            // Enlaces temporales (el bucket es privado); la primera es la portada
            'fotos' => $a->fotos->map(fn ($f): array => [
                'id' => $f->id,
                'orden' => $f->orden,
                'url' => $this->archivos->urlTemporal($f->ruta),
            ])->values()->all(),
        ];
    }
}
