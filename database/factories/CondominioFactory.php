<?php

namespace Database\Factories;

use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Condominio>
 */
class CondominioFactory extends Factory
{
    protected $model = Condominio::class;

    private static int $secuencia = 100;

    public function definition(): array
    {
        return [
            'codigo' => sprintf('SF-%04d', ++self::$secuencia),
            'nombre' => 'Conjunto '.fake()->unique()->lastName(),
            'tipo' => 'conjunto',
            'total_unidades' => 50,
            'estado' => Condominio::ESTADO_ACTIVO,
        ];
    }

    public function suspendido(): static
    {
        return $this->state(fn () => ['estado' => Condominio::ESTADO_SUSPENDIDO]);
    }
}
