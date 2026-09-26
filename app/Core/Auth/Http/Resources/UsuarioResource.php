<?php

namespace App\Core\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UsuarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->name,
            'email' => $this->email,
            // Solo condominios con membresía activa: son los que aparecen en el selector
            'condominios' => $this->whenLoaded('condominiosActivos', fn () => $this->condominiosActivos->map(fn ($c) => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
                'es_principal' => (bool) $c->pivot->es_principal,
                'estado' => $c->estado,
                'marca' => $c->marca,
            ])->values()),
        ];
    }
}
