<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehiculo
 */
class VehiculoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unidad_id' => $this->unidad_id,
            'placa' => $this->placa,
            'tipo' => $this->tipo,
            'marca' => $this->marca,
            'modelo' => $this->modelo,
            'color' => $this->color,
        ];
    }
}
