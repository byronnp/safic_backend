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
            'clave' => $this->clave,
            'nombre' => $this->nombre,
            'max_administrativos' => $this->max_administrativos,
            // Dinero como texto con dos decimales: "2.00"
            'valor_unidad_sugerido' => $this->valor_unidad_sugerido,
        ];
    }
}
