<?php

namespace App\Core\Auth\Http\Resources;

use App\Core\Marca\MarcaPublica;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Membresia;
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
            'condominios' => $this->whenLoaded('condominiosActivos', fn () => $this->condominiosActivos->map(function (Condominio $c): array {
                /** @var Membresia $membresia */
                $membresia = $c->getRelation('pivot');

                return [
                    'id' => $c->id,
                    'codigo' => $c->codigo,
                    'nombre' => $c->nombre,
                    'es_principal' => (bool) $membresia->es_principal,
                    'estado' => $c->estado,
                    'marca' => MarcaPublica::de($c),
                ];
            })->values()),
            // Perfil de plataforma (super admin, soporte, cobranza…) o null.
            // Con él, el frontend abre el panel de plataforma aunque no haya condominios.
            'plataforma' => $this->resource->contextoPlataforma(),
        ];
    }
}
