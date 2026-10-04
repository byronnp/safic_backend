<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\Request;

/**
 * Unidad con sus ocupantes vigentes (pantalla Detalle de unidad).
 *
 * @mixin Unidad
 */
class UnidadDetalleResource extends UnidadResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'ocupantes' => OcupanteResource::collection(
                $this->ocupantesVigentes->sortBy([['es_principal', 'desc'], ['fecha_inicio', 'asc']])->values(),
            ),
        ];
    }
}
