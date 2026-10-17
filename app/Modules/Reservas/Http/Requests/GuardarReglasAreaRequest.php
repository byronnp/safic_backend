<?php

namespace App\Modules\Reservas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reglas de uso de un área común (PUT: viajan todas).
 */
class GuardarReglasAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:reservas.gestionar)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'abre' => ['required', 'date_format:H:i'],
            'cierra' => ['required', 'date_format:H:i'],
            'duracion_min_horas' => ['required', 'integer', 'min:1', 'max:24'],
            'duracion_max_horas' => ['required', 'integer', 'min:1', 'max:24'],
            'anticipacion_min_horas' => ['required', 'integer', 'min:0', 'max:720'],
            'anticipacion_max_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'capacidad' => ['present', 'nullable', 'integer', 'min:1', 'max:5000'],
            'reservas_por_unidad_mes' => ['present', 'nullable', 'integer', 'min:1', 'max:60'],
            'dias_semana' => ['required', 'array', 'min:1'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'costo' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'garantia' => ['required', 'decimal:0,2', 'min:0', 'max:99999999'],
            'reglamento' => ['present', 'nullable', 'string', 'max:2000'],
            'requiere_aprobacion' => ['required', 'boolean'],
            'restringible_por_mora' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $d = $this->all();
            if (isset($d['abre'], $d['cierra']) && $d['abre'] >= $d['cierra']) {
                $v->errors()->add('cierra', 'La hora de cierre debe ser posterior a la de apertura.');
            }
            if (isset($d['duracion_min_horas'], $d['duracion_max_horas']) && (int) $d['duracion_min_horas'] > (int) $d['duracion_max_horas']) {
                $v->errors()->add('duracion_max_horas', 'La duración máxima no puede ser menor a la mínima.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'abre.required' => 'Indica la hora de apertura.',
            'abre.date_format' => 'La hora de apertura no es válida.',
            'cierra.required' => 'Indica la hora de cierre.',
            'cierra.date_format' => 'La hora de cierre no es válida.',
            'duracion_min_horas.min' => 'La duración mínima es de 1 hora.',
            'duracion_min_horas.required' => 'Indica la duración mínima.',
            'duracion_max_horas.required' => 'Indica la duración máxima.',
            'anticipacion_min_horas.required' => 'Indica con cuántas horas de anticipación se reserva.',
            'anticipacion_max_dias.required' => 'Indica hasta con cuántos días se reserva.',
            'dias_semana.required' => 'Elige al menos un día de la semana.',
            'dias_semana.min' => 'Elige al menos un día de la semana.',
            'costo.required' => 'Indica el costo de uso (0 si es gratis).',
            'costo.decimal' => 'El costo tiene hasta dos decimales.',
            'garantia.required' => 'Indica la garantía (0 si no tiene).',
            'garantia.decimal' => 'La garantía tiene hasta dos decimales.',
            'reglamento.max' => 'El reglamento tiene máximo 2000 caracteres.',
        ];
    }
}
