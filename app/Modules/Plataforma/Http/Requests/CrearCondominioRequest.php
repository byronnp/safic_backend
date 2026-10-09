<?php

namespace App\Modules\Plataforma\Http\Requests;

use App\Core\Validation\Rules\CedulaEc;
use App\Core\Validation\Rules\RucEc;
use App\Modules\Finanzas\Services\ReglasCobro;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos del asistente "Nuevo condominio" (5 pasos). El permiso lo exige la ruta
 * (permission:plataforma.condominios).
 */
class CrearCondominioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('administrador.email'))) {
            $this->merge(['administrador' => ['email' => mb_strtolower(trim($this->input('administrador.email')))] + (array) $this->input('administrador')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $metodo = $this->input('cobro.metodo');

        return [
            // Paso 1 · datos generales y contrato con la plataforma
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(Condominio::TIPOS)],
            'ruc' => ['required', 'string', new RucEc, Rule::unique('condominios', 'ruc')],
            'razon_social' => ['required', 'string', 'max:160'],
            'provincia_codigo' => ['required', 'string', Rule::exists('ubicacion_provincias', 'codigo')],
            'canton_codigo' => ['required', 'string', Rule::exists('ubicacion_cantones', 'codigo')->where('provincia_codigo', $this->input('provincia_codigo'))],
            'parroquia_codigo' => ['required', 'string', Rule::exists('ubicacion_parroquias', 'codigo')->where('canton_codigo', $this->input('canton_codigo'))],
            'direccion' => ['required', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'regex:/^0\d{8,9}$/'],
            'email_contacto' => ['nullable', 'email', 'max:160'],
            'total_unidades' => ['required', 'integer', 'min:1', 'max:10000'],
            'plan_codigo' => ['required', 'string', Rule::exists('planes', 'codigo')->where('activo', true)],
            'valor_unidad' => ['required', 'decimal:0,2', 'gt:0', 'max:1000'],

            // Paso 2 · ubicación (Ecuador continental y Galápagos)
            'latitud' => ['required', 'numeric', 'between:-5.1,1.7'],
            'longitud' => ['required', 'numeric', 'between:-92.1,-75.1'],

            // Paso 3 · cobro de cuotas a residentes
            'cobro' => ['required', 'array'],
            ...ReglasCobro::reglas($metodo, 'cobro.'),
            'cobro.primera_cuota' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->format('Y-m')],

            // Paso 4 · amenidades del catálogo
            'amenidades' => ['nullable', 'array', 'max:60'],
            'amenidades.*.amenidad_id' => ['required', 'integer', 'distinct', Rule::exists('amenidades_catalogo', 'id')->where('activa', true)],
            'amenidades.*.cantidad' => ['required', 'integer', 'between:1,999'],

            // Paso 5 · administrador
            'administrador' => ['required', 'array'],
            'administrador.cedula' => ['required', 'string', new CedulaEc],
            'administrador.nombre' => ['required', 'string', 'max:120'],
            'administrador.email' => ['required', 'email', 'max:160'],
            'administrador.celular' => ['nullable', 'string', 'regex:/^09\d{8}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ruc.unique' => 'Ya existe un condominio con ese RUC.',
            'telefono.regex' => 'Escribe un teléfono de 9 o 10 dígitos que empiece con 0.',
            'administrador.celular.regex' => 'El celular tiene 10 dígitos y empieza con 09.',
            'canton_codigo.exists' => 'El cantón no pertenece a la provincia elegida.',
            'parroquia_codigo.exists' => 'La parroquia no pertenece al cantón elegido.',
            'cobro.primera_cuota.after_or_equal' => 'La primera cuota no puede ser de un mes pasado.',
        ];
    }
}
