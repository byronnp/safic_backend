<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Modules\Unidades\Models\Bloque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bloque
 */
class BloqueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'orden' => $this->orden,
        ];
    }
}
