<?php

namespace App\Core\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AceptarInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(10)->letters()->numbers()],
            // LOPDP: sin aceptar el aviso de privacidad no se crea la cuenta
            'acepta_privacidad' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña debe tener al menos una letra.',
            'password.numbers' => 'La contraseña debe tener al menos un número.',
            'acepta_privacidad.accepted' => 'Para continuar, acepta el aviso de privacidad.',
            'acepta_privacidad.required' => 'Para continuar, acepta el aviso de privacidad.',
        ];
    }
}
