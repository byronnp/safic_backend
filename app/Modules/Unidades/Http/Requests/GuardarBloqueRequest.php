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

        // Al editar, solo viaja lo que cambia y el nombre puede repetirse consigo mismo
        $actual = $this->route('bloque');
        $unico = Rule::unique('bloques', 'nombre')->where('condominio_id', $condominioId);
        if ($actual !== null) {
            $unico->ignore((int) $actual);
        }

        return [
            'nombre' => [$actual === null ? 'required' : 'sometimes', 'string', 'min:1', 'max:60', $unico],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ];
    }
}
