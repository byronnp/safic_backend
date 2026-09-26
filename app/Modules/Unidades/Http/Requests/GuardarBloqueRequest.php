<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarBloqueRequest extends FormRequest
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
            'nombre' => [
                'required', 'string', 'max:60',
                Rule::unique('bloques', 'nombre')->where('condominio_id', $condominioId),
            ],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
