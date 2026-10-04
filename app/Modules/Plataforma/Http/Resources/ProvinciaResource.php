<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Modules\Plataforma\Models\Canton;
use App\Modules\Plataforma\Models\Parroquia;
use App\Modules\Plataforma\Models\Provincia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Provincia con sus cantones y parroquias (árbol para los selectores del alta).
 *
 * @mixin Provincia
 */
class ProvinciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'cantones' => $this->cantones->map(fn (Canton $canton): array => [
                'codigo' => $canton->codigo,
                'nombre' => $canton->nombre,
                'parroquias' => $canton->parroquias->map(fn (Parroquia $parroquia): array => [
                    'codigo' => $parroquia->codigo,
                    'nombre' => $parroquia->nombre,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
