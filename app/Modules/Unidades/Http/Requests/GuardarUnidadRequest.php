<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finanzas\Actions\ObtenerMetodoCobroAction;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear (POST) o editar (PATCH) una unidad. Los montos dependen del método de
 * cobro del condominio:
 * - alicuota: la alícuota es obligatoria; admite valor personalizado.
 * - tipo: admite valor personalizado.
 * - unidad: la cuota mensual es obligatoria.
 * - general: no pide montos (todas pagan el valor general).
 * La alícuota se acepta con cualquier método: la usan las asambleas.
 */
class GuardarUnidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:unidades.editar)
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('codigo'))) {
            $this->merge(['codigo' => mb_strtoupper(trim($this->input('codigo')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $condominioId = app(TenantContext::class)->require();
        $metodo = app(ObtenerMetodoCobroAction::class)->execute();
        $editando = $this->isMethod('PATCH');
        $obligatorio = $editando ? ['sometimes', 'required'] : ['required'];
        /** @var Unidad|null $unidad */
        $unidad = $this->route('unidad');

        return [
            'codigo' => [
                ...$obligatorio, 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9 .\-\/]*$/',
                Rule::unique('unidades', 'codigo')
                    ->where('condominio_id', $condominioId)
                    ->whereNull('deleted_at')
                    ->ignore($unidad?->id),
            ],
            'bloque_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('bloques', 'id')->where('condominio_id', $condominioId),
            ],
            'tipo' => [...$obligatorio, Rule::in(Unidad::TIPOS)],
            'piso' => ['sometimes', 'nullable', 'integer', 'between:-5,200'],
            'area_m2' => [...$obligatorio, 'numeric', 'gt:0', 'max:99999.99', 'decimal:0,2'],
            'responsable_pago' => ['sometimes', Rule::in(Unidad::RESPONSABLES_PAGO)],
            'alicuota' => $metodo === 'alicuota'
                ? [...$obligatorio, 'numeric', 'gt:0', 'max:100', 'decimal:0,4']
                : ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'cuota_mensual' => $metodo === 'unidad'
                ? [...$obligatorio, 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2']
                : ['prohibited'],
            'valor_personalizado' => in_array($metodo, ['tipo', 'alicuota'], true)
                ? ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2']
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Escribe el código de la unidad.',
            'codigo.max' => 'El código tiene máximo 20 caracteres.',
            'codigo.regex' => 'Usa letras, números, espacios, guion, punto o barra (ej. A-102).',
            'codigo.unique' => 'Ya existe una unidad con ese código.',
            'bloque_id.exists' => 'El bloque no existe en este condominio.',
            'tipo.required' => 'Elige el tipo de unidad.',
            'tipo.in' => 'El tipo debe ser departamento, casa, local, parqueadero o bodega.',
            'piso.integer' => 'El piso es un número entero.',
            'piso.between' => 'El piso debe estar entre -5 y 200.',
            'area_m2.required' => 'Escribe el área en m².',
            'area_m2.numeric' => 'El área es un número.',
            'area_m2.gt' => 'El área debe ser mayor que 0.',
            'area_m2.max' => 'El área es demasiado grande.',
            'area_m2.decimal' => 'El área admite hasta 2 decimales.',
            'responsable_pago.in' => 'El responsable de pago es el propietario o el inquilino.',
            'alicuota.required' => 'Escribe la alícuota: el condominio cobra por alícuota.',
            'alicuota.numeric' => 'La alícuota es un porcentaje.',
            'alicuota.gt' => 'La alícuota debe ser mayor que 0.',
            'alicuota.max' => 'La alícuota no puede pasar de 100 %.',
            'alicuota.decimal' => 'La alícuota admite hasta 4 decimales.',
            'cuota_mensual.required' => 'Escribe la cuota mensual: el condominio cobra por unidad.',
            'cuota_mensual.numeric' => 'La cuota mensual es un monto.',
            'cuota_mensual.gt' => 'La cuota mensual debe ser mayor que 0.',
            'cuota_mensual.max' => 'La cuota mensual es demasiado alta.',
            'cuota_mensual.decimal' => 'La cuota mensual admite hasta 2 decimales.',
            'cuota_mensual.prohibited' => 'La cuota mensual solo se usa cuando el condominio cobra por unidad.',
            'valor_personalizado.numeric' => 'El valor personalizado es un monto.',
            'valor_personalizado.gt' => 'El valor personalizado debe ser mayor que 0.',
            'valor_personalizado.max' => 'El valor personalizado es demasiado alto.',
            'valor_personalizado.decimal' => 'El valor personalizado admite hasta 2 decimales.',
            'valor_personalizado.prohibited' => 'El valor personalizado solo se usa cuando el condominio cobra por tipo o por alícuota.',
        ];
    }
}
