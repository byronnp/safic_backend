<?php

namespace App\Modules\Usuarios\Http\Requests;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambiar el perfil, la vigencia del acceso o desactivar/reactivar (PATCH). Solo viaja lo que cambia.
 */
class ActualizarUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:usuarios.gestionar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = CarbonImmutable::parse(app(Calendario::class)->hoy());

        return [
            'rol' => ['sometimes', 'required', Rule::in(array_map(fn (Rol $r) => $r->value, Rol::asignables()))],
            'acceso_hasta' => [
                'sometimes', 'nullable', 'date_format:Y-m-d',
                'after_or_equal:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->addYears(2)->toDateString(),
            ],
            'activo' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rol.in' => 'Elige administrador, contador, guardia o mantenimiento.',
            'acceso_hasta.date_format' => 'La fecha no es válida.',
            'acceso_hasta.after_or_equal' => 'La fecha de vencimiento no puede ser pasada.',
            'acceso_hasta.before_or_equal' => 'El acceso puede durar hasta 2 años; luego se renueva.',
            'activo.boolean' => 'El estado no es válido.',
        ];
    }
}
