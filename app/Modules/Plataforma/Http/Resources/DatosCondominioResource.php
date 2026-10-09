<?php

namespace App\Modules\Plataforma\Http\Resources;

use App\Core\Marca\MarcaPublica;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datos del propio condominio para su administrador (Configuración › Datos del condominio).
 * RUC, razón social, tipo y plan se muestran pero los cambia la plataforma.
 *
 * @mixin Condominio
 */
class DatosCondominioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'telefono' => $this->telefono,
            'email_contacto' => $this->email_contacto,
            'direccion' => $this->direccion,
            'provincia' => $this->provincia === null ? null : ['codigo' => $this->provincia->codigo, 'nombre' => $this->provincia->nombre],
            'canton' => $this->canton === null ? null : ['codigo' => $this->canton->codigo, 'nombre' => $this->canton->nombre],
            'parroquia' => $this->parroquia === null ? null : ['codigo' => $this->parroquia->codigo, 'nombre' => $this->parroquia->nombre],
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
            'marca' => MarcaPublica::de($this->resource),
        ];
    }
}
