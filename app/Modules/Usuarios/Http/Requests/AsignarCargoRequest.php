<?php

namespace App\Modules\Usuarios\Http\Requests;

use App\Core\Tenancy\Calendario;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nombrar a una persona en un cargo de la directiva (POST). El acta respalda el nombramiento.
 */
class AsignarCargoRequest extends FormRequest
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
            'persona_id' => ['required', 'integer', Rule::exists('personas', 'id')->where('condominio_id', app(TenantContext::class)->require())->whereNull('deleted_at')],
            'acta' => ['required', 'string', 'max:80'],
            'periodo_hasta' => ['required', 'date_format:Y-m-d', 'after:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->addYears(4)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'persona_id.required' => 'Elige a la persona.',
            'persona_id.exists' => 'Esa persona no existe en este condominio.',
            'acta.required' => 'Escribe el acta que respalda el nombramiento.',
            'acta.max' => 'El acta tiene máximo 80 caracteres.',
            'periodo_hasta.required' => 'Elige hasta cuándo dura el periodo.',
            'periodo_hasta.date_format' => 'La fecha no es válida.',
            'periodo_hasta.after' => 'El periodo debe terminar después de hoy.',
            'periodo_hasta.before_or_equal' => 'El periodo puede durar hasta 4 años.',
        ];
    }
}
