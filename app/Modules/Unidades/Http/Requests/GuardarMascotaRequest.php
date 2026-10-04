<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Modules\Unidades\Models\Mascota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarMascotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:unidades.editar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $obligatorio = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];

        return [
            'nombre' => [...$obligatorio, 'string', 'max:40'],
            'especie' => [...$obligatorio, Rule::in(Mascota::ESPECIES)],
            'raza' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de la mascota.',
            'nombre.max' => 'El nombre tiene máximo 40 caracteres.',
            'especie.required' => 'Elige la especie.',
            'especie.in' => 'La especie es perro, gato, ave u otro.',
            'raza.max' => 'La raza tiene máximo 40 caracteres.',
        ];
    }
}
