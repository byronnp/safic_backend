<?php

namespace App\Modules\Finanzas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pago del residente (multipart): las cuotas que paga, el número del comprobante y su foto o PDF
 * (hasta 5 MB). El monto lo calcula el servidor.
 */
class EnviarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:cuenta.propia)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unidad_id' => ['required', 'integer'],
            'cuotas' => ['required', 'array', 'min:1', 'max:24'],
            'cuotas.*' => ['integer', 'distinct'],
            'numero_comprobante' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[0-9A-Za-z\-\/\. ]+$/'],
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unidad_id.required' => 'Elige la unidad.',
            'cuotas.required' => 'Elige al menos una cuota.',
            'cuotas.min' => 'Elige al menos una cuota.',
            'cuotas.max' => 'Elige hasta 24 cuotas por pago.',
            'numero_comprobante.required' => 'Escribe el número del comprobante.',
            'numero_comprobante.min' => 'El número del comprobante es muy corto.',
            'numero_comprobante.regex' => 'El número del comprobante solo lleva letras, números y guiones.',
            'comprobante.required' => 'Sube la foto o el PDF del comprobante.',
            'comprobante.file' => 'Sube la foto o el PDF del comprobante.',
            'comprobante.mimes' => 'El comprobante debe ser una foto (JPG o PNG) o un PDF.',
            'comprobante.mimetypes' => 'El comprobante debe ser una foto (JPG o PNG) o un PDF.',
            'comprobante.max' => 'El comprobante pesa más de 5 MB.',
        ];
    }
}
