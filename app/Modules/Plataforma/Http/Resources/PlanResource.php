<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Modules\Plataforma\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'limite_administrativos' => $this->limite_administrativos,
            'valor_unidad_sugerido' => $this->valor_unidad_sugerido,
        ];
    }
}
