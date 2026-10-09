<?php

namespace App\Modules\Amenidades\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso público del módulo Amenidades: agregar amenidades al condominio activo
 * copiando los valores que vienen del catálogo. Si ya existe una con el mismo nombre,
 * se actualiza la cantidad.
 */
final class AsignarAmenidadesAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<array{amenidad_catalogo_id: int|null, nombre: string, categoria?: string|null, cantidad: int, reservable: bool, esencial: bool, requiere_aprobacion: bool}>  $amenidades
     */
    public function execute(array $amenidades): int
    {
        $this->tenant->require();

        return DB::transaction(function () use ($amenidades): int {
            foreach ($amenidades as $amenidad) {
                CondominioAmenidad::updateOrCreate(
                    ['nombre' => $amenidad['nombre']],
                    $amenidad + ['activa' => true],
                );
            }

            return count($amenidades);
        });
    }
}
