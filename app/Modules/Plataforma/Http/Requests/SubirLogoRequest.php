<?php

namespace App\Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Logo del condominio (multipart): PNG de hasta 1 MB. Solo PNG: un SVG puede traer
 * scripts y se sirve desde nuestro dominio.
 */
class SubirLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:condominio.editar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required', 'file', 'mimes:png', 'mimetypes:image/png', 'max:1024',
                'dimensions:min_width=64,min_height=64,max_width=2000,max_height=2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Elige el logo (PNG).',
            'archivo.file' => 'Elige el logo (PNG).',
            'archivo.mimes' => 'El logo debe ser un PNG.',
            'archivo.mimetypes' => 'El logo debe ser un PNG.',
            'archivo.max' => 'El logo pesa más de 1 MB.',
            'archivo.dimensions' => 'El logo debe medir entre 64 y 2000 píxeles por lado.',
        ];
    }
}
