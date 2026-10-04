<?php

namespace App\Modules\Plataforma\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReenviarInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:plataforma.condominios)
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $usuario */
        $usuario = $this->route('usuario');

        return [
            // Opcional: para corregir el correo antes de reenviar
            'email' => ['sometimes', 'nullable', 'email', 'max:160', Rule::unique('users', 'email')->ignore($usuario->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'Escribe un correo válido.',
            'email.max' => 'El correo tiene máximo 160 caracteres.',
            'email.unique' => 'Ese correo ya pertenece a otra cuenta.',
        ];
    }
}
