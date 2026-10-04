<?php

namespace App\Modules\Unidades\Http\Resources;

use App\Core\Permissions\Permiso;
use App\Core\Privacy\DatosPersonales;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Documento, teléfono y correo salen enmascarados para quien no tiene
 * residentes.ver_datos (guardia, directiva…). `datos_enmascarados` lo indica.
 *
 * @mixin Persona
 */
class PersonaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completo = (bool) $request->user()?->can(Permiso::ResidentesVerDatos->value);

        return [
            'id' => $this->id,
            'tipo_documento' => $this->tipo_documento,
            'documento' => $completo ? $this->documento : DatosPersonales::enmascararDocumento($this->documento),
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'nombre_completo' => $this->nombreCompleto(),
            'telefono' => $this->telefono === null ? null
                : ($completo ? $this->telefono : DatosPersonales::enmascararTelefono($this->telefono)),
            'email' => $this->email === null ? null
                : ($completo ? $this->email : DatosPersonales::enmascararEmail($this->email)),
            'datos_enmascarados' => ! $completo,
        ];
    }
}
