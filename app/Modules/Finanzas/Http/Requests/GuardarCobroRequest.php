<?php

namespace App\Modules\Finanzas\Http\Requests;

use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Services\ReglasCobro;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar el cobro de cuotas (PUT). Reemplaza la configuración completa.
 * `aplica_desde` es el primer mes que se emite con el cambio: desde el mes
 * siguiente (en la hora del condominio) hasta 12 meses adelante.
 */
class GuardarCobroRequest extends FormRequest
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
        $proximo = CarbonImmutable::parse(app(Calendario::class)->hoy())->startOfMonth()->addMonth();

        return [
            ...ReglasCobro::reglas($this->input('metodo'), ''),
            'aplica_desde' => [
                'required', 'date_format:Y-m',
                'after_or_equal:'.$proximo->format('Y-m'),
                'before_or_equal:'.$proximo->addMonths(11)->format('Y-m'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metodo.required' => 'Elige cómo se cobra.',
            'metodo.in' => 'El método de cobro no es válido.',
            'cuota_general.required' => 'Escribe la cuota mensual.',
            'cuota_general.decimal' => 'La cuota admite hasta 2 decimales.',
            'cuota_general.gt' => 'La cuota debe ser mayor que 0.',
            'cuota_general.max' => 'La cuota es demasiado alta.',
            'presupuesto_mensual.required' => 'Escribe el presupuesto mensual.',
            'presupuesto_mensual.decimal' => 'El presupuesto admite hasta 2 decimales.',
            'presupuesto_mensual.gt' => 'El presupuesto debe ser mayor que 0.',
            'valores_tipo.required' => 'Escribe el valor de al menos un tipo de unidad.',
            'valores_tipo.*.valor.required' => 'Escribe el valor.',
            'valores_tipo.*.valor.decimal' => 'El valor admite hasta 2 decimales.',
            'valores_tipo.*.valor.gt' => 'El valor debe ser mayor que 0.',
            'valores_tipo.*.tipo.distinct' => 'Un tipo de unidad no se repite.',
            'dia_vencimiento.required' => 'Elige el día de vencimiento.',
            'dia_vencimiento.between' => 'El día de vencimiento es del 1 al 28, o 0 para el último día del mes.',
            'aplica_desde.required' => 'Elige desde qué mes aplica.',
            'aplica_desde.date_format' => 'El mes no es válido.',
            'aplica_desde.after_or_equal' => 'El cambio aplica desde el mes siguiente: las cuotas ya emitidas no cambian.',
            'aplica_desde.before_or_equal' => 'Elige un mes de los próximos 12.',
        ];
    }
}
