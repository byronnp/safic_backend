<?php

namespace App\Modules\Amenidades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de una amenidad (multipart): JPG o PNG de hasta 5 MB. Sin SVG ni formatos con scripts;
 * las medidas máximas evitan imágenes que revientan la memoria al procesarse.
 */
class SubirFotoAmenidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:amenidades.gestionar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'foto' => [
                'required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120',
                'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => 'Elige la foto (JPG o PNG).',
            'foto.file' => 'Elige la foto (JPG o PNG).',
            'foto.mimes' => 'La foto debe ser JPG o PNG.',
            'foto.mimetypes' => 'La foto debe ser JPG o PNG.',
            'foto.max' => 'La foto pesa más de 5 MB.',
            'foto.dimensions' => 'La foto debe medir entre 200 y 8000 píxeles por lado.',
        ];
    }
}
