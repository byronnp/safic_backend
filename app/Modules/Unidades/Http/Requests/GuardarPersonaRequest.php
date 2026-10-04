<?php

namespace App\Modules\Unidades\Http\Requests;

use App\Core\Privacy\DatosPersonales;
use App\Core\Validation\Rules\CedulaEc;
use App\Core\Validation\Rules\RucEc;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear (POST) o editar (PATCH) una persona. El documento se valida según su tipo
 * y no se repite en el condominio (se compara por hash: está cifrado).
 */
class GuardarPersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:unidades.editar)
    }

    protected function prepareForValidation(): void
    {
        foreach (['documento', 'telefono'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => preg_replace('/[\s.\-]/', '', $this->input($campo))]);
            }
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $obligatorio = $this->isMethod('PATCH') ? ['sometimes', 'required'] : ['required'];
        $tipo = $this->input('tipo_documento', $this->persona()?->tipo_documento);

        $documento = match ($tipo) {
            'cedula' => [new CedulaEc],
            'ruc' => [new RucEc],
            default => ['regex:/^[A-Za-z0-9]{5,20}$/'],
        };

        return [
            'tipo_documento' => [...$obligatorio, Rule::in(Persona::TIPOS_DOCUMENTO)],
            // Al cambiar el tipo hay que volver a enviar el documento
            'documento' => [
                ...($this->isMethod('PATCH') && ! $this->has('tipo_documento') ? ['sometimes'] : []),
                'required', 'string', ...$documento,
            ],
            'nombres' => [...$obligatorio, 'string', 'max:80'],
            'apellidos' => [...$obligatorio, 'string', 'max:80'],
            'telefono' => [...$obligatorio, 'regex:/^09\d{8}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('documento') || ! $this->filled('documento')) {
                return;
            }

            $tipo = (string) $this->input('tipo_documento', $this->persona()?->tipo_documento);
            $hash = DatosPersonales::hashDocumento($tipo, (string) $this->input('documento'));

            $repetida = Persona::query()
                ->where('documento_hash', $hash)
                ->when($this->persona(), fn ($q, Persona $p) => $q->whereKeyNot($p->id))
                ->exists();

            if ($repetida) {
                $validator->errors()->add('documento', 'Ya existe una persona con ese documento en el condominio.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'Elige el tipo de documento.',
            'tipo_documento.in' => 'El documento es cédula, RUC o pasaporte.',
            'documento.required' => $this->isMethod('PATCH')
                ? 'Al cambiar el tipo, escribe también el número de documento.'
                : 'Escribe el número de documento.',
            'documento.regex' => 'El pasaporte tiene de 5 a 20 letras o números.',
            'nombres.required' => 'Escribe los nombres.',
            'nombres.max' => 'Los nombres tienen máximo 80 caracteres.',
            'apellidos.required' => 'Escribe los apellidos.',
            'apellidos.max' => 'Los apellidos tienen máximo 80 caracteres.',
            'telefono.required' => 'Escribe el celular.',
            'telefono.regex' => 'El celular tiene 10 dígitos y empieza con 09.',
            'email.email' => 'Escribe un correo válido.',
            'email.max' => 'El correo tiene máximo 160 caracteres.',
        ];
    }

    private function persona(): ?Persona
    {
        $persona = $this->route('persona');

        return $persona instanceof Persona ? $persona : null;
    }
}
