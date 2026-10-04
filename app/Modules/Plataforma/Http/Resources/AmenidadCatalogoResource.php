<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AmenidadCatalogo
 */
class AmenidadCatalogoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'categoria' => $this->categoria,
            'descripcion' => $this->descripcion,
            'reservable' => $this->reservable,
            'esencial' => $this->esencial,
            'requiere_aprobacion' => $this->requiere_aprobacion,
        ];
    }
}
