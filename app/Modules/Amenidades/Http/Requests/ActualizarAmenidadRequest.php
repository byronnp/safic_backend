<?php

namespace App\Modules\Amenidades\Http\Requests;

use App\Core\Tenancy\Calendario;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar ubicación, mantenimiento o estado de una amenidad (PATCH). Solo viaja lo que cambia.
 */
class ActualizarAmenidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:amenidades.gestionar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ubicacion' => ['sometimes', 'nullable', 'string', 'max:80'],
            'activa' => ['sometimes', 'required', 'boolean'],
            // null saca la amenidad de mantenimiento
            'mantenimiento_hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.app(Calendario::class)->hoy()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ubicacion.max' => 'La ubicación tiene máximo 80 caracteres.',
            'activa.boolean' => 'El estado no es válido.',
            'mantenimiento_hasta.date_format' => 'La fecha no es válida.',
            'mantenimiento_hasta.after_or_equal' => 'La fecha de fin del mantenimiento no puede ser pasada.',
        ];
    }
}
