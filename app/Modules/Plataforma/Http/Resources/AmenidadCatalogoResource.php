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
            'clave' => $this->clave,
            'nombre' => $this->nombre,
            'icono' => $this->icono,
            'reservable' => $this->reservable,
            'esencial' => $this->esencial,
        ];
    }
}
