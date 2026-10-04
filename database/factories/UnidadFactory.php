<?php

namespace Database\Factories;

use App\Modules\Unidades\Models\Unidad;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Se usa dentro de un condominio activo: TenantContext::run($id, fn () => Unidad::factory()->create()).
 *
 * @extends Factory<Unidad>
 */
class UnidadFactory extends Factory
{
    protected $model = Unidad::class;

    public function definition(): array
    {
        return [
            'codigo' => fake()->unique()->bothify('?-###'),
            'tipo' => 'departamento',
            'piso' => 1,
            'area_m2' => '84.00',
            'responsable_pago' => 'propietario',
        ];
    }

    public function parqueadero(): static
    {
        return $this->state(fn () => ['tipo' => 'parqueadero', 'piso' => null, 'area_m2' => '12.50']);
    }
}
