<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del super admin: pasar una amenidad propia de un condominio al catálogo
 * global. El condominio la conserva sin cambios (solo queda vinculada al nuevo tipo).
 * Corre en el contexto de ese condominio: nunca toca amenidades de otros.
 */
final class PromoverAmenidadPropiaAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function execute(int $condominioId, int $amenidadId): AmenidadCatalogo
    {
        return $this->tenant->run($condominioId, function () use ($amenidadId): AmenidadCatalogo {
            return DB::transaction(function () use ($amenidadId): AmenidadCatalogo {
                $amenidad = CondominioAmenidad::query()->whereNull('amenidad_catalogo_id')->lockForUpdate()->findOrFail($amenidadId);

                if (AmenidadCatalogo::query()->whereRaw('lower(nombre) = ?', [mb_strtolower($amenidad->nombre)])->exists()) {
                    throw new ApiException('AMENIDAD_EN_CATALOGO', "\"{$amenidad->nombre}\" ya existe en el catálogo global.", 409);
                }

                $tipo = AmenidadCatalogo::create([
                    'nombre' => $amenidad->nombre,
                    'categoria' => $amenidad->categoria ?? 'servicios',
                    'reservable' => $amenidad->reservable,
                    'esencial' => $amenidad->esencial,
                    'requiere_aprobacion' => $amenidad->requiere_aprobacion,
                    'orden' => ((int) AmenidadCatalogo::query()->max('orden')) + 1,
                    'activa' => true,
                ]);

                $amenidad->update(['amenidad_catalogo_id' => $tipo->id]);

                return $tipo;
            });
        });
    }
}
