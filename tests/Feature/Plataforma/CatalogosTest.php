<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Canton;
use App\Modules\Plataforma\Models\Parroquia;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Plataforma\Models\Provincia;
use Database\Seeders\CatalogosSeeder;

beforeEach(function () {
    $this->seed(CatalogosSeeder::class);
    $user = User::factory()->dePlataforma(Rol::SuperAdmin)->create();
    $this->token = auth('api')->tokenById($user->id);
});

it('lista los planes con su límite de usuarios administrativos', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/plataforma/planes')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0', ['clave' => 'basico', 'nombre' => 'Básico', 'max_administrativos' => 2, 'valor_unidad_sugerido' => '2.00'])
        ->assertJsonPath('data.1.max_administrativos', 3)
        ->assertJsonPath('data.2.max_administrativos', 4);
});

it('no lista planes inactivos', function () {
    Plan::query()->where('clave', Plan::COMPLETO)->update(['activo' => false]);

    $this->withToken($this->token)
        ->getJson('/api/v1/plataforma/planes')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('devuelve tipos de condominio, métodos de cobro y amenidades', function () {
    $datos = $this->withToken($this->token)
        ->getJson('/api/v1/plataforma/catalogos')
        ->assertOk()
        ->json('data');

    expect(array_column($datos['tipos_condominio'], 'valor'))->toBe(['conjunto', 'edificio', 'urbanizacion', 'mixto'])
        ->and(array_column($datos['metodos_cobro'], 'valor'))->toBe(['valor_general', 'por_tipo', 'por_alicuota', 'por_unidad'])
        ->and($datos['amenidades'])->toHaveCount(9)
        ->and(collect($datos['amenidades'])->firstWhere('clave', 'guardiania'))->toMatchArray(['esencial' => true, 'reservable' => false])
        ->and(collect($datos['amenidades'])->firstWhere('clave', 'piscina'))->toMatchArray(['esencial' => false, 'reservable' => true]);
});

it('siembra las 24 provincias y arma el árbol de ubicaciones', function () {
    $pichincha = Provincia::query()->where('codigo', '17')->firstOrFail();
    $quito = Canton::query()->create(['provincia_id' => $pichincha->id, 'codigo' => '1701', 'nombre' => 'Quito']);
    Parroquia::query()->create(['canton_id' => $quito->id, 'codigo' => '170155', 'nombre' => 'Conocoto']);

    $provincias = $this->withToken($this->token)
        ->getJson('/api/v1/plataforma/ubicaciones')
        ->assertOk()
        ->json('data');

    expect($provincias)->toHaveCount(24)
        ->and(collect($provincias)->firstWhere('codigo', '17'))->toBe([
            'codigo' => '17',
            'nombre' => 'Pichincha',
            'cantones' => [['codigo' => '1701', 'nombre' => 'Quito', 'parroquias' => [['codigo' => '170155', 'nombre' => 'Conocoto']]]],
        ]);
});

it('el seeder no pisa lo que cambió el super admin', function () {
    Plan::query()->where('clave', Plan::BASICO)->update(['valor_unidad_sugerido' => '1.50']);

    $this->seed(CatalogosSeeder::class);

    expect(Plan::query()->where('clave', Plan::BASICO)->value('valor_unidad_sugerido'))->toBe('1.50')
        ->and(Plan::query()->count())->toBe(3);
});
