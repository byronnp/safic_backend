<?php

namespace App\Modules\Plataforma\Http\Requests;

use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear (POST) o editar (PATCH) un tipo del catálogo global de amenidades. Cambiar los
 * valores sugeridos no altera a los condominios que ya la configuraron (reciben una copia).
 */
class GuardarCatalogoAmenidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:plataforma.condominios)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editando = $this->isMethod('PATCH');
        $obligatorio = $editando ? ['sometimes', 'required'] : ['required'];
        $id = $this->route('amenidad');

        return [
            'nombre' => [
                ...$obligatorio, 'string', 'min:2', 'max:80',
                // Sin distinguir mayúsculas: "piscina" y "Piscina" son la misma amenidad
                function (string $atributo, mixed $valor, \Closure $falla) use ($id): void {
                    $existe = AmenidadCatalogo::query()
                        ->whereRaw('lower(nombre) = ?', [mb_strtolower(trim((string) $valor))])
                        ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
                        ->exists();
                    if ($existe) {
                        $falla('Ya existe una amenidad con ese nombre en el catálogo.');
                    }
                },
            ],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:200'],
            'categoria' => [...$obligatorio, Rule::in(CondominioAmenidad::CATEGORIAS)],
            'reservable' => ['sometimes', 'boolean'],
            'esencial' => ['sometimes', 'boolean'],
            'requiere_aprobacion' => ['sometimes', 'boolean'],
            'capacidad' => ['sometimes', 'nullable', 'integer', 'between:1,9999'],
            'duracion_maxima_min' => ['sometimes', 'nullable', 'integer', 'between:15,1440'],
            'orden' => ['sometimes', 'integer', 'between:0,999'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de la amenidad.',
            'nombre.min' => 'El nombre tiene mínimo 2 caracteres.',
            'nombre.max' => 'El nombre tiene máximo 80 caracteres.',
            'descripcion.max' => 'La descripción tiene máximo 200 caracteres.',
            'categoria.required' => 'Elige la categoría.',
            'categoria.in' => 'Elige recreación, deporte, social, servicios o seguridad.',
            'capacidad.between' => 'La capacidad va de 1 a 9999.',
            'duracion_maxima_min.between' => 'La duración máxima va de 15 minutos a 24 horas.',
            'orden.between' => 'El orden va de 0 a 999.',
        ];
    }
}
