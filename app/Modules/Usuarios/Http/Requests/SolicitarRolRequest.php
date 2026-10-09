<?php

namespace App\Modules\Usuarios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir un rol nuevo a la plataforma (POST).
 */
class SolicitarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:usuarios.gestionar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'descripcion' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre sugerido para el rol.',
            'nombre.min' => 'El nombre tiene mínimo 3 caracteres.',
            'nombre.max' => 'El nombre tiene máximo 60 caracteres.',
            'descripcion.required' => 'Cuéntanos qué debe poder hacer.',
            'descripcion.min' => 'Cuéntanos un poco más: mínimo 10 caracteres.',
            'descripcion.max' => 'La descripción tiene máximo 500 caracteres.',
        ];
    }
}
