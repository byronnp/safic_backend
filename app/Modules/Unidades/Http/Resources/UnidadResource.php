<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Montos y porcentajes como texto decimal ("84.00", "0.6200"), nunca float.
 *
 * @mixin Unidad
 */
class UnidadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'bloque' => $this->whenLoaded('bloque', fn () => $this->bloque === null ? null : [
                'id' => $this->bloque->id,
                'nombre' => $this->bloque->nombre,
            ]),
            'tipo' => $this->tipo,
            'piso' => $this->piso,
            'area_m2' => $this->area_m2,
            'alicuota' => $this->alicuota,
            'cuota_mensual' => $this->cuota_mensual,
            'valor_personalizado' => $this->valor_personalizado,
            'responsable_pago' => $this->responsable_pago,
            'estado' => $this->estado(),
        ];
    }
}
