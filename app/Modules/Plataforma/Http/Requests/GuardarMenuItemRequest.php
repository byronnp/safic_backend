<?php

namespace App\Modules\Plataforma\Http\Requests;

use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Permiso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear (POST) o editar (PATCH) un ítem del menú del sistema. El ícono es obligatorio
 * (Material Symbols Rounded) y el permiso debe ser del mismo ámbito del menú.
 */
class GuardarMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El permiso lo exige la ruta (permission:plataforma.roles)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editando = $this->isMethod('PATCH');
        $obligatorio = $editando ? ['sometimes', 'required'] : ['required'];

        return [
            'ambito' => $editando ? ['prohibited'] : ['required', Rule::in([MenuItem::AMBITO_CONDOMINIO, MenuItem::AMBITO_PLATAFORMA])],
            'padre_id' => $editando ? ['prohibited'] : ['nullable', 'integer'],
            'etiqueta' => [...$obligatorio, 'string', 'min:2', 'max:60'],
            'icono' => [...$obligatorio, 'string', 'max:60', 'regex:/^sym_r_[a-z0-9_]+$/'],
            'ruta' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'permiso' => ['sometimes', 'nullable', 'string', Rule::in(array_map(fn (Permiso $p) => $p->value, Permiso::cases()))],
            'seccion' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:40'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permiso = $this->input('permiso');
            if (! is_string($permiso) || Permiso::tryFrom($permiso) === null) {
                return;
            }

            $ambito = $this->isMethod('PATCH')
                ? MenuItem::query()->whereKey($this->route('item'))->value('ambito')
                : $this->input('ambito');

            if ($ambito !== null && Permiso::from($permiso)->esDePlataforma() !== ($ambito === MenuItem::AMBITO_PLATAFORMA)) {
                $validator->errors()->add('permiso', 'Ese permiso no corresponde a este menú.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ambito.required' => 'Elige el menú (condominio o plataforma).',
            'ambito.in' => 'Elige el menú (condominio o plataforma).',
            'etiqueta.required' => 'Escribe la etiqueta.',
            'etiqueta.min' => 'La etiqueta tiene mínimo 2 caracteres.',
            'etiqueta.max' => 'La etiqueta tiene máximo 60 caracteres.',
            'icono.required' => 'Elige un ícono.',
            'icono.regex' => 'Elige un ícono de la lista (Material Symbols Rounded).',
            'ruta.regex' => 'La pantalla solo lleva minúsculas, números y guiones.',
            'permiso.in' => 'Ese permiso no existe.',
            'roles.array' => 'Los perfiles no son válidos.',
        ];
    }
}
