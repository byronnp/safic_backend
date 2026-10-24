<?php

use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\DemoSeeder;

/*
| Los usuarios de demostración deben poder entrar y tener el perfil que dice el README. Aquí se comprueban los guardias: entran a su
| condominio con los permisos de garita y sin ninguno de administración ni de finanzas.
*/

beforeEach(function () {
    $this->seed(CatalogosSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->entrar = function (string $email) {
        cambiarDeUsuario();
        $r = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Safic2026!'])->assertOk();

        return [$r->json('usuario') ?? $r->json('data.usuario'), $r->json('access_token') ?? $r->json('data.access_token')];
    };
});

it('hay un guardia de prueba por condominio, con su condominio principal', function () {
    [$marco, $token] = ($this->entrar)('guardia@jardinesdelvalle.ec');
    [$luis] = ($this->entrar)('guardia@losarupos.ec');

    expect($token)->toBeString()->and($marco['condominios'])->toHaveCount(1)->and($marco['condominios'][0])->toMatchArray(['nombre' => 'Conjunto Jardines del Valle', 'es_principal' => true])
        ->and($luis['condominios'])->toHaveCount(1)->and($luis['condominios'][0]['nombre'])->toBe('Conjunto Los Arupos');
});

it('el guardia tiene los permisos de garita y ninguno de administración', function () {
    [$marco, $token] = ($this->entrar)('guardia@jardinesdelvalle.ec');
    $condominio = (string) $marco['condominios'][0]['id'];

    $contexto = $this->withToken($token)->withHeader('X-Condominio-Id', $condominio)->getJson('/api/v1/me/contexto')->assertOk()->json('data');

    expect($contexto['roles'])->toBe(['guardia'])
        ->and($contexto['permisos'])->toContain('garita.registrar', 'garita.directorio', 'paquetes.gestionar', 'reservas.garita')
        ->and($contexto['permisos'])->not->toContain('finanzas.ver', 'unidades.editar', 'usuarios.gestionar', 'cuenta.propia');
});

it('el guardia de un condominio no entra al otro', function () {
    [, $token] = ($this->entrar)('guardia@jardinesdelvalle.ec');
    $otro = User::query()->where('email', 'guardia@losarupos.ec')->first()->membresias()->value('condominio_id');

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro)->getJson('/api/v1/me/contexto')->assertForbidden();
});

it('volver a correr el seeder no duplica a los usuarios de prueba', function () {
    $this->seed(DemoSeeder::class);

    expect(User::query()->whereIn('email', ['guardia@jardinesdelvalle.ec', 'guardia@losarupos.ec'])->count())->toBe(2);
});
