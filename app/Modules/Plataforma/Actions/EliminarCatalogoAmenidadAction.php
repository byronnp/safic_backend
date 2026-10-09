<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Amenidades\Actions\ResumenAmenidadesPorCondominioAction;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso del super admin: eliminar un tipo del catálogo que ningún condominio usa.
 * Si alguno la usa solo se puede desactivar (los condominios conservan su copia).
 */
final class EliminarCatalogoAmenidadAction
{
    public function __construct(private readonly ResumenAmenidadesPorCondominioAction $resumen) {}

    public function execute(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $tipo = AmenidadCatalogo::query()->lockForUpdate()->findOrFail($id);
            $usan = $this->resumen->execute()['uso'][$tipo->id] ?? 0;

            if ($usan > 0) {
                throw new ApiException(
                    'AMENIDAD_EN_USO',
                    "Está en uso en {$usan} ".($usan === 1 ? 'condominio' : 'condominios').': no se puede eliminar, solo desactivar.',
                    409,
                    ['condominios' => $usan],
                );
            }

            $tipo->delete();
        });
    }
}
