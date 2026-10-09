<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tipo del catálogo para el super admin: todos los valores sugeridos y en cuántos
 * condominios se usa (`uso`, cuando se calcula).
 *
 * @mixin AmenidadCatalogo
 */
class CatalogoAmenidadAdminResource extends JsonResource
{
    /**
     * @param  array<int, int>|null  $uso  Condominios que usan cada tipo (id → cantidad)
     */
    public function __construct($resource, private readonly ?array $uso = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'categoria' => $this->categoria,
            'reservable' => $this->reservable,
            'esencial' => $this->esencial,
            'requiere_aprobacion' => $this->requiere_aprobacion,
            'capacidad' => $this->capacidad,
            'duracion_maxima_min' => $this->duracion_maxima_min,
            'orden' => $this->orden,
            'activa' => $this->activa,
            'uso' => $this->uso === null ? null : ($this->uso[$this->id] ?? 0),
        ];
    }
}
