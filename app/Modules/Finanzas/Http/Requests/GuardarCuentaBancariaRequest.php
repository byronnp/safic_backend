<?php

namespace App\Modules\Finanzas\Http\Requests;

use App\Modules\Finanzas\Models\CuentaBancaria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear (POST) o editar (PATCH) una cuenta bancaria del condominio.
 */
class GuardarCuentaBancariaRequest extends FormRequest
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
        $obligatorio = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];

        return [
            'banco' => [...$obligatorio, 'string', 'min:2', 'max:60'],
            'tipo' => [...$obligatorio, Rule::in(CuentaBancaria::TIPOS)],
            'numero' => [...$obligatorio, 'string', 'regex:/^[0-9A-Za-z-]{5,30}$/'],
            'titular' => [...$obligatorio, 'string', 'min:3', 'max:120'],
            'es_principal' => ['sometimes', 'boolean'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'banco.required' => 'Escribe el banco.',
            'banco.min' => 'El banco tiene mínimo 2 caracteres.',
            'tipo.required' => 'Elige corriente o ahorros.',
            'tipo.in' => 'Elige corriente o ahorros.',
            'numero.required' => 'Escribe el número de cuenta.',
            'numero.regex' => 'El número de cuenta lleva de 5 a 30 letras, números o guiones.',
            'titular.required' => 'Escribe el titular de la cuenta.',
            'titular.min' => 'El titular tiene mínimo 3 caracteres.',
        ];
    }
}
