<?php

namespace App\Modules\Plataforma\Http\Requests;

use App\Core\Validation\Rules\RucEc;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos de un condominio que edita el super admin de la plataforma. Solo viaja lo que cambia.
 */
class EditarCondominioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (plataforma.condominios-editar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = (int) $this->route('condominio');

        return [
            'nombre' => ['sometimes', 'string', 'min:2', 'max:120'],
            'tipo' => ['sometimes', Rule::in(Condominio::TIPOS)],
            'ruc' => ['sometimes', 'string', new RucEc, Rule::unique('condominios', 'ruc')->ignore($id)],
            'razon_social' => ['sometimes', 'string', 'min:2', 'max:160'],
            'provincia_codigo' => ['sometimes', 'string', Rule::exists('ubicacion_provincias', 'codigo')],
            'canton_codigo' => ['sometimes', 'string', Rule::exists('ubicacion_cantones', 'codigo')],
            'parroquia_codigo' => ['sometimes', 'string', Rule::exists('ubicacion_parroquias', 'codigo')],
            'direccion' => ['sometimes', 'string', 'max:200'],
            'telefono' => ['sometimes', 'nullable', 'string', 'regex:/^0\d{8,9}$/'],
            'email_contacto' => ['sometimes', 'nullable', 'email', 'max:160'],
            'total_unidades' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'plan_codigo' => ['sometimes', 'string', Rule::exists('planes', 'codigo')->where('activo', true)],
            'valor_unidad' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:1000'],
            'latitud' => ['sometimes', 'numeric', 'between:-5.1,1.7'],
            'longitud' => ['sometimes', 'numeric', 'between:-92.1,-75.1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.min' => 'El nombre es muy corto.',
            'ruc.unique' => 'Ya hay un condominio con ese RUC.',
            'razon_social.min' => 'La razón social es muy corta.',
            'telefono.regex' => 'El teléfono debe empezar con 0 y tener 9 o 10 dígitos.',
            'email_contacto.email' => 'El correo no es válido.',
            'total_unidades.min' => 'Debe tener al menos una unidad.',
            'plan_codigo.exists' => 'Elige un plan vigente.',
            'valor_unidad.gt' => 'El valor por unidad debe ser mayor a cero.',
        ];
    }
}
