<?php

namespace Database\Factories;

use App\Modules\Unidades\Models\Bloque;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Se usa dentro de un condominio activo: TenantContext::run($id, fn () => Bloque::factory()->create()).
 *
 * @extends Factory<Bloque>
 */
class BloqueFactory extends Factory
{
    protected $model = Bloque::class;

    public function definition(): array
    {
        return [
            'nombre' => 'Torre '.fake()->unique()->bothify('?#'),
            'orden' => 0,
        ];
    }
}
