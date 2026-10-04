<?php

namespace Database\Factories;

use App\Modules\Unidades\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Se usa dentro de un condominio activo: TenantContext::run($id, fn () => Persona::factory()->create()).
 *
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    public function definition(): array
    {
        return [
            'tipo_documento' => 'pasaporte',
            'documento' => fake()->unique()->bothify('P#######'),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'telefono' => '09'.fake()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
