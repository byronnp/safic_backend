<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Modules\Unidades\Models\Ocupante;
use Illuminate\Foundation\Http\FormRequest;

class FinalizarOcupanteRequest extends FormRequest
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
        /** @var Ocupante $ocupante */
        $ocupante = $this->route('ocupante');

        return [
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$ocupante->fecha_inicio->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_fin.required' => 'Indica la fecha de fin.',
            'fecha_fin.date_format' => 'La fecha de fin no es válida.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ];
    }
}
