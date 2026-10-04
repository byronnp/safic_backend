<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Core\Validation\Rules\PlacaEc;
use App\Modules\Unidades\Models\Vehiculo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear (POST) o editar (PATCH) un vehículo. La placa no se repite en el condominio.
 */
class GuardarVehiculoRequest extends FormRequest
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
            'placa' => [...$obligatorio, 'string', 'max:10', new PlacaEc],
            'tipo' => [...$obligatorio, Rule::in(Vehiculo::TIPOS)],
            'marca' => ['sometimes', 'nullable', 'string', 'max:40'],
            'modelo' => ['sometimes', 'nullable', 'string', 'max:40'],
            'color' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('placa') || ! is_string($this->input('placa'))) {
                return;
            }

            $vehiculo = $this->route('vehiculo');
            $repetida = Vehiculo::query()
                ->where('placa', PlacaEc::normalizar($this->input('placa')))
                ->when($vehiculo instanceof Vehiculo, fn ($q) => $q->whereKeyNot($vehiculo->id))
                ->exists();

            if ($repetida) {
                $validator->errors()->add('placa', 'Esa placa ya está registrada en el condominio.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placa.required' => 'Escribe la placa.',
            'placa.max' => 'La placa no es válida (ej. PBA-1234 o una moto IA-123B).',
            'tipo.required' => 'Elige si es auto o moto.',
            'tipo.in' => 'El vehículo es auto o moto.',
            'marca.max' => 'La marca tiene máximo 40 caracteres.',
            'modelo.max' => 'El modelo tiene máximo 40 caracteres.',
            'color.max' => 'El color tiene máximo 30 caracteres.',
        ];
    }
}
