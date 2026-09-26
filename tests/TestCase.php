<?php

namespace Tests;

use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('jwt/private.pem'))) {
            Artisan::call('safic:jwt-keys');
            // La configuración ya se cargó sin llaves: se completan ahora.
            config([
                'jwt.keys.private' => file_get_contents(storage_path('jwt/private.pem')),
                'jwt.keys.public' => file_get_contents(storage_path('jwt/public.pem')),
            ]);
        }

        // Catálogo de roles y permisos en cada prueba con base de datos
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed(RolesYPermisosSeeder::class);
        }
    }

    /**
     * Las migraciones corren con el usuario dueño (pgsql_owner); las pruebas usan
     * la conexión de la app (safic_app), que respeta Row Level Security.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_owner',
            '--drop-views' => true,
            '--drop-types' => true,
        ];
    }
}
