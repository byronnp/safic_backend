<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Core\Audit\RegistroBitacora;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegistroBitacora
 */
class RegistroBitacoraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->created_at->toIso8601String(),
            'usuario_id' => $this->user_id,
            'usuario' => $this->user_nombre,
            'evento' => $this->evento,
            'entidad' => $this->entidad,
            'entidad_id' => $this->entidad_id,
            'etiqueta' => $this->etiqueta,
            'condominio_id' => $this->condominio_id,
            'antes' => $this->valores_anteriores,
            'despues' => $this->valores_nuevos,
            'ip' => $this->ip,
        ];
    }
}
