<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Condominio visto por el panel de plataforma (lista y detalle). Los administradores
 * vienen precargados en el atributo "administradores" (ver CondominioController).
 *
 * @mixin Condominio
 */
class CondominioPlataformaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mensualidad = $this->valor_unidad === null
            ? null
            : bcmul((string) $this->total_unidades, (string) $this->valor_unidad, 2);

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'estado' => $this->estado,
            'prueba_hasta' => $this->prueba_hasta?->toDateString(),
            'total_unidades' => $this->total_unidades,
            'valor_unidad' => $this->valor_unidad,
            'mensualidad' => $mensualidad,
            'plan' => $this->plan === null ? null : ['codigo' => $this->plan->codigo, 'nombre' => $this->plan->nombre],
            'ubicacion' => [
                'provincia' => $this->provincia?->nombre,
                'canton' => $this->canton?->nombre,
                'parroquia' => $this->parroquia?->nombre,
                'direccion' => $this->direccion,
                'latitud' => $this->latitud,
                'longitud' => $this->longitud,
            ],
            'contacto' => ['telefono' => $this->telefono, 'email' => $this->email_contacto],
            'administradores' => $this->resource->getAttribute('administradores') ?? [],
            'creado_en' => $this->created_at->toIso8601String(),
        ];
    }
}
