<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Support\Facades\DB;

/**
 * Consulta pública del módulo Amenidades para el panel de plataforma: cuántos condominios
 * usan cada tipo del catálogo y qué amenidades propias crearon los condominios.
 *
 * Las amenidades de cada condominio tienen RLS y una petición de plataforma no fija
 * condominio, así que se recorre cada condominio en su propio contexto (nunca se
 * debilita la política). Es una consulta de administración, no de uso diario.
 */
final class ResumenAmenidadesPorCondominioAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{uso: array<int, int>, propias: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        /** @var array<int, int> $uso */
        $uso = [];
        $propias = [];

        foreach (DB::table('condominios')->orderBy('nombre')->get(['id', 'nombre']) as $condominio) {
            $amenidades = $this->tenant->run((int) $condominio->id, fn () => CondominioAmenidad::query()->orderBy('nombre')->get());

            foreach ($amenidades->pluck('amenidad_catalogo_id')->filter()->unique() as $tipoId) {
                $uso[(int) $tipoId] = ($uso[(int) $tipoId] ?? 0) + 1;
            }

            foreach ($amenidades->whereNull('amenidad_catalogo_id') as $a) {
                $propias[] = [
                    'id' => $a->id,
                    'condominio_id' => (int) $condominio->id,
                    'condominio' => (string) $condominio->nombre,
                    'nombre' => $a->nombre,
                    'categoria' => $a->categoria,
                    'reservable' => $a->reservable,
                    'esencial' => $a->esencial,
                    'requiere_aprobacion' => $a->requiere_aprobacion,
                    'activa' => $a->activa,
                ];
            }
        }

        return ['uso' => $uso, 'propias' => $propias];
    }
}
