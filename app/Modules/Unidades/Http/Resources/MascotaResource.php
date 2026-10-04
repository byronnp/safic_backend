<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Mascota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Mascota
 */
class MascotaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unidad_id' => $this->unidad_id,
            'nombre' => $this->nombre,
            'especie' => $this->especie,
            'raza' => $this->raza,
        ];
    }
}
