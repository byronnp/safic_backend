<?php

namespace Database\Factories;

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    /**
     * Miembro activo de un condominio, opcionalmente con un rol en él.
     */
    public function miembroDe(Condominio $condominio, ?Rol $rol = null, bool $principal = true): static
    {
        return $this->afterCreating(function (User $user) use ($condominio, $rol, $principal) {
            $user->membresias()->create([
                'condominio_id' => $condominio->id,
                'es_principal' => $principal,
                'activo' => true,
            ]);

            if ($rol !== null) {
                $anterior = getPermissionsTeamId();
                setPermissionsTeamId($condominio->id);
                $user->assignRole($rol->value);
                setPermissionsTeamId($anterior);
                $user->unsetRelation('roles');
            }
        });
    }

    /**
     * Usuario del equipo de la plataforma (super admin, soporte…): rol en el
     * "condominio" 0 y sin membresía en ningún condominio.
     */
    public function dePlataforma(Rol $rol = Rol::SuperAdmin): static
    {
        return $this->afterCreating(function (User $user) use ($rol) {
            $anterior = getPermissionsTeamId();
            setPermissionsTeamId(Rol::EQUIPO_PLATAFORMA);
            $user->assignRole($rol->value);
            setPermissionsTeamId($anterior);
            $user->unsetRelation('roles');
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
