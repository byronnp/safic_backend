<?php

namespace App\Modules\Usuarios\Http\Requests;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Core\Validation\Rules\CedulaEc;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sumar a una persona al equipo (POST). El contador necesita fecha de vencimiento del acceso.
 */
class InvitarUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:usuarios.gestionar)
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
        $hoy = CarbonImmutable::parse(app(Calendario::class)->hoy());

        return [
            'nombre' => ['required', 'string', 'max:120'],
            'cedula' => ['required', 'string', new CedulaEc],
            'email' => ['required', 'email', 'max:160'],
            'celular' => ['nullable', 'string', 'regex:/^09\d{8}$/'],
            'rol' => ['required', Rule::in(array_map(fn (Rol $r) => $r->value, Rol::asignables()))],
            'acceso_hasta' => [
                Rule::requiredIf($this->input('rol') === Rol::Contador->value), 'nullable', 'date_format:Y-m-d',
                'after:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->addYears(2)->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre.',
            'nombre.max' => 'El nombre tiene máximo 120 caracteres.',
            'cedula.required' => 'Escribe la cédula.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Escribe un correo válido.',
            'celular.regex' => 'El celular tiene 10 dígitos y empieza con 09.',
            'rol.required' => 'Elige el perfil.',
            'rol.in' => 'Elige administrador, contador, guardia o mantenimiento. Los cargos de la directiva se asignan en la pestaña Directiva.',
            'acceso_hasta.required' => 'El contador necesita una fecha de vencimiento del acceso.',
            'acceso_hasta.date_format' => 'La fecha no es válida.',
            'acceso_hasta.after' => 'La fecha de vencimiento debe ser posterior a hoy.',
            'acceso_hasta.before_or_equal' => 'El acceso puede durar hasta 2 años; luego se renueva.',
        ];
    }
}
