<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Ocupante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ocupante
 */
class OcupanteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unidad_id' => $this->unidad_id,
            'persona' => new PersonaResource($this->whenLoaded('persona')),
            'relacion' => $this->relacion,
            'es_principal' => $this->es_principal,
            'fecha_inicio' => $this->fecha_inicio->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'vigente' => $this->vigente(),
        ];
    }
}
