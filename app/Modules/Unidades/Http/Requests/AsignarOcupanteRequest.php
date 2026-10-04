<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Unidades\Models\Ocupante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AsignarOcupanteRequest extends FormRequest
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
        $condominioId = app(TenantContext::class)->require();

        return [
            'persona_id' => [
                'required', 'integer',
                Rule::exists('personas', 'id')->where('condominio_id', $condominioId)->whereNull('deleted_at'),
            ],
            'relacion' => ['required', Rule::in(Ocupante::RELACIONES)],
            // Un contacto de emergencia no vive en la unidad: no puede ser el principal
            'es_principal' => ['sometimes', 'boolean', Rule::prohibitedIf(
                $this->boolean('es_principal') && $this->input('relacion') === 'contacto_emergencia',
            )],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'persona_id.required' => 'Elige la persona.',
            'persona_id.exists' => 'La persona no existe en este condominio.',
            'relacion.required' => 'Elige la relación con la unidad.',
            'relacion.in' => 'La relación es propietario, inquilino, residente o contacto de emergencia.',
            'es_principal.prohibited' => 'Un contacto de emergencia no puede ser el ocupante principal.',
            'fecha_inicio.required' => 'Indica desde cuándo.',
            'fecha_inicio.date_format' => 'La fecha de inicio no es válida.',
            'fecha_fin.date_format' => 'La fecha de fin no es válida.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
        ];
    }
}
