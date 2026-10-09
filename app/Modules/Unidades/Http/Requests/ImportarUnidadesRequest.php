<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Modules\Unidades\Services\LectorExcelUnidades;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Importar unidades (multipart): el .xlsx y, para crearlas de verdad, confirmar=1.
 */
class ImportarUnidadesRequest extends FormRequest
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
        return [
            'archivo' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:2048'],
            'confirmar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Elige el archivo de Excel (.xlsx).',
            'archivo.file' => 'Elige el archivo de Excel (.xlsx).',
            'archivo.extensions' => 'El archivo debe ser un Excel (.xlsx).',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx).',
            'archivo.max' => 'El archivo pesa más de 2 MB. Máximo '.LectorExcelUnidades::MAX_FILAS.' filas.',
            'confirmar.boolean' => 'El valor de confirmar no es válido.',
        ];
    }
}
