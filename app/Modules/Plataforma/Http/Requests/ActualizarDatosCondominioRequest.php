<?php

namespace App\Modules\Plataforma\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editar los datos del propio condominio (PATCH). Solo viaja lo que cambia.
 * No se editan aquí el RUC, la razón social, el tipo ni el plan: son de la plataforma.
 * Provincia, cantón y parroquia van juntos, y latitud con longitud también.
 */
class ActualizarDatosCondominioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:condominio.editar)
    }

    protected function prepareForValidation(): void
    {
        foreach (['color_primario', 'color_acento'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => mb_strtoupper(trim($this->input($campo)))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'telefono' => ['sometimes', 'nullable', 'string', 'regex:/^0\d{8,9}$/'],
            'email_contacto' => ['sometimes', 'nullable', 'email', 'max:160'],
            'direccion' => ['sometimes', 'required', 'string', 'max:200'],

            'provincia_codigo' => ['required_with:canton_codigo,parroquia_codigo', 'string', Rule::exists('ubicacion_provincias', 'codigo')],
            'canton_codigo' => ['required_with:provincia_codigo,parroquia_codigo', 'string', Rule::exists('ubicacion_cantones', 'codigo')->where('provincia_codigo', $this->input('provincia_codigo'))],
            'parroquia_codigo' => ['required_with:provincia_codigo,canton_codigo', 'string', Rule::exists('ubicacion_parroquias', 'codigo')->where('canton_codigo', $this->input('canton_codigo'))],

            // Ecuador continental y Galápagos
            'latitud' => ['required_with:longitud', 'numeric', 'between:-5.1,1.7'],
            'longitud' => ['required_with:latitud', 'numeric', 'between:-92.1,-75.1'],

            // Solo primario y acento se personalizan; null los restablece
            'color_primario' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'color_acento' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del condominio.',
            'nombre.max' => 'El nombre tiene máximo 120 caracteres.',
            'telefono.regex' => 'Escribe un teléfono de 9 o 10 dígitos que empiece con 0.',
            'email_contacto.email' => 'Escribe un correo válido.',
            'direccion.required' => 'Escribe la dirección.',
            'direccion.max' => 'La dirección tiene máximo 200 caracteres.',
            'provincia_codigo.required_with' => 'Elige la provincia.',
            'canton_codigo.required_with' => 'Elige el cantón.',
            'canton_codigo.exists' => 'El cantón no pertenece a la provincia elegida.',
            'parroquia_codigo.required_with' => 'Elige la parroquia.',
            'parroquia_codigo.exists' => 'La parroquia no pertenece al cantón elegido.',
            'latitud.between' => 'La ubicación debe estar en Ecuador.',
            'longitud.between' => 'La ubicación debe estar en Ecuador.',
            'latitud.required_with' => 'Marca la ubicación en el mapa.',
            'longitud.required_with' => 'Marca la ubicación en el mapa.',
            'color_primario.regex' => 'Escribe un color hex válido, por ejemplo #1F4C9A.',
            'color_acento.regex' => 'Escribe un color hex válido, por ejemplo #F0B35A.',
        ];
    }
}
