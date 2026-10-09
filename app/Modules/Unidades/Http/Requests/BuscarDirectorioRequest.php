<?php

namespace App\Modules\Unidades\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Búsqueda del directorio de garita. Pide al menos dos letras o números para que
 * no se pueda listar a todos los residentes con una búsqueda vacía.
 */
class BuscarDirectorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:garita.directorio)
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('buscar'))) {
            $this->merge(['buscar' => trim($this->query('buscar'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'buscar' => ['required', 'string', 'max:60', 'regex:/[\p{L}\p{N}].*[\p{L}\p{N}]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'buscar.required' => 'Escribe un nombre, una unidad o una placa.',
            'buscar.max' => 'La búsqueda tiene máximo 60 caracteres.',
            'buscar.regex' => 'Escribe al menos 2 letras o números.',
        ];
    }
}
