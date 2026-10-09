<?php

namespace App\Modules\Amenidades\Http\Requests;

use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Agregar amenidades (POST): del catálogo o propias del condominio. Una propia no puede
 * llamarse como una del catálogo (se agrega desde el catálogo para mantener sus valores).
 */
class AgregarAmenidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:amenidades.gestionar)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origen' => ['required', Rule::in(['catalogo', 'propia'])],
            'amenidad_catalogo_id' => [
                Rule::requiredIf($this->input('origen') === 'catalogo'), 'nullable', 'integer',
                Rule::exists('amenidades_catalogo', 'id')->where('activa', true),
            ],
            'nombre' => [Rule::requiredIf($this->input('origen') === 'propia'), 'nullable', 'string', 'min:2', 'max:70'],
            'categoria' => [Rule::requiredIf($this->input('origen') === 'propia'), 'nullable', Rule::in(CondominioAmenidad::CATEGORIAS)],
            'reservable' => ['sometimes', 'boolean'],
            'cantidad' => ['required', 'integer', 'between:1,50'],
            'ubicacion' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $nombre = trim((string) $this->input('nombre'));
            if ($this->input('origen') === 'propia' && $nombre !== ''
                && AmenidadCatalogo::query()->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
                $validator->errors()->add('nombre', "\"{$nombre}\" ya existe en el catálogo. Agrégala desde «Del catálogo» para mantener los valores sugeridos.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origen.required' => 'Elige si es del catálogo o propia.',
            'origen.in' => 'Elige si es del catálogo o propia.',
            'amenidad_catalogo_id.required' => 'Elige un tipo del catálogo.',
            'amenidad_catalogo_id.exists' => 'Ese tipo ya no está en el catálogo.',
            'nombre.required' => 'Escribe el nombre de la amenidad.',
            'nombre.min' => 'El nombre tiene mínimo 2 caracteres.',
            'nombre.max' => 'El nombre tiene máximo 70 caracteres.',
            'categoria.required' => 'Elige la categoría.',
            'categoria.in' => 'Elige recreación, deporte, social, servicios o seguridad.',
            'cantidad.required' => 'Indica cuántas son.',
            'cantidad.between' => 'La cantidad va de 1 a 50.',
            'ubicacion.max' => 'La ubicación tiene máximo 80 caracteres.',
        ];
    }
}
