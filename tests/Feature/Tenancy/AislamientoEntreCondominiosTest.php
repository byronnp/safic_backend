<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Pruebas obligatorias de aislamiento. Cada módulo nuevo agrega las suyas
| siguiendo este patrón: un condominio nunca ve ni modifica datos de otro.
*/

beforeEach(function () {
    $this->a = Condominio::factory()->create(['nombre' => 'Condominio A']);
    $this->b = Condominio::factory()->create(['nombre' => 'Condominio B']);

    enCondominio($this->a, fn () => Bloque::factory()->createMany([['nombre' => 'A-Torre 1'], ['nombre' => 'A-Torre 2']]));
    enCondominio($this->b, fn () => Bloque::factory()->create(['nombre' => 'B-Torre 1']));
});

it('lista solo los bloques del condominio indicado en el header', function () {
    [, $token] = usuarioConToken($this->a);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->getJson('/api/v1/bloques')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['nombre' => 'B-Torre 1']);
});

it('rechaza un condominio donde el usuario no tiene membresía', function () {
    [, $token] = usuarioConToken($this->a);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->b->id)
        ->getJson('/api/v1/bloques')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'CONDOMINIO_NO_PERMITIDO');
});

it('exige el header X-Condominio-Id', function () {
    [, $token] = usuarioConToken($this->a);

    $this->withToken($token)
        ->getJson('/api/v1/bloques')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'CONDOMINIO_REQUERIDO');
});

it('rechaza una membresía inactiva', function () {
    [$user, $token] = usuarioConToken($this->a);
    $user->membresias()->update(['activo' => false]);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->getJson('/api/v1/bloques')
        ->assertForbidden();
});

it('rechaza un condominio suspendido', function () {
    [, $token] = usuarioConToken($this->a);
    $this->a->update(['estado' => Condominio::ESTADO_SUSPENDIDO]);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->getJson('/api/v1/bloques')
        ->assertForbidden();
});

it('crea el bloque en el condominio activo', function () {
    [, $token] = usuarioConToken($this->a);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->postJson('/api/v1/bloques', ['nombre' => 'Torre Nueva'])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Torre Nueva');

    expect(enCondominio($this->a, fn () => Bloque::where('nombre', 'Torre Nueva')->exists()))->toBeTrue()
        ->and(enCondominio($this->b, fn () => Bloque::where('nombre', 'Torre Nueva')->exists()))->toBeFalse();
});

it('permite el mismo nombre de bloque en condominios distintos pero no repetido en uno', function () {
    [, $token] = usuarioConToken($this->a);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->postJson('/api/v1/bloques', ['nombre' => 'B-Torre 1'])
        ->assertCreated();

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->postJson('/api/v1/bloques', ['nombre' => 'A-Torre 1'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDACION');
});

it('exige el permiso del rol dentro del condominio', function () {
    [, $token] = usuarioConToken($this->a, Rol::Residente);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->a->id)
        ->getJson('/api/v1/bloques')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'SIN_PERMISO');
});

it('un rol en un condominio no da permisos en otro', function () {
    [$user, $token] = usuarioConToken($this->a, Rol::Administrador);
    // Miembro de B pero como residente (sin unidades.ver)
    $user->membresias()->create(['condominio_id' => $this->b->id, 'es_principal' => false, 'activo' => true]);
    setPermissionsTeamId($this->b->id);
    $user->assignRole(Rol::Residente->value);
    setPermissionsTeamId(null);

    $this->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $this->b->id)
        ->getJson('/api/v1/bloques')
        ->assertForbidden();
});

describe('Row Level Security (barrera de la base de datos)', function () {
    it('filtra aunque se salte el scope de Eloquent', function () {
        expect(enCondominio($this->a, fn () => DB::table('bloques')->count()))->toBe(2)
            ->and(enCondominio($this->b, fn () => DB::table('bloques')->count()))->toBe(1);
    });

    it('no devuelve filas sin condominio activo', function () {
        expect(DB::table('bloques')->count())->toBe(0)
            ->and(Bloque::count())->toBe(0);
    });

    it('impide escribir filas de otro condominio', function () {
        // DB::transaction crea un savepoint: el error que llega es el de RLS.
        enCondominio($this->a, fn () => DB::transaction(fn () => DB::table('bloques')->insert([
            'condominio_id' => $this->b->id,
            'nombre' => 'Intruso',
            'orden' => 0,
        ])));
    })->throws(QueryException::class, 'row-level security');
});

describe('Transacción por petición (RLS con SET LOCAL)', function () {
    beforeEach(function () {
        // Ruta solo de prueba, en el mismo grupo que las rutas de condominio.
        Route::middleware(['api', 'auth:api', 'condominio'])->post('/api/v1/_prueba/bloque', function () {
            Bloque::create(['nombre' => 'Temporal', 'orden' => 0]);

            abort_if(request()->boolean('fallar'), 409);

            return response()->json(['ok' => true], 201);
        });
    });

    it('deshace lo escrito si la petición termina en error', function () {
        [, $token] = usuarioConToken($this->a);

        $this->withToken($token)
            ->withHeader('X-Condominio-Id', (string) $this->a->id)
            ->postJson('/api/v1/_prueba/bloque', ['fallar' => true])
            ->assertStatus(409);

        expect(enCondominio($this->a, fn () => Bloque::where('nombre', 'Temporal')->exists()))->toBeFalse();
    });

    it('confirma lo escrito si la petición termina bien', function () {
        [, $token] = usuarioConToken($this->a);

        $this->withToken($token)
            ->withHeader('X-Condominio-Id', (string) $this->a->id)
            ->postJson('/api/v1/_prueba/bloque')
            ->assertCreated();

        expect(enCondominio($this->a, fn () => Bloque::where('nombre', 'Temporal')->exists()))->toBeTrue();
    });

    it('deja la petición sin condominio activo al terminar', function () {
        [, $token] = usuarioConToken($this->a);

        $this->withToken($token)
            ->withHeader('X-Condominio-Id', (string) $this->a->id)
            ->postJson('/api/v1/_prueba/bloque')
            ->assertCreated();

        expect(app(TenantContext::class)->has())->toBeFalse()
            ->and(DB::scalar("select current_setting('app.condominio_id', true)"))->toBeIn(['', null]);
    });
});
