<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Mascota;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use App\Modules\Unidades\Models\Vehiculo;
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

describe('Unidades', function () {
    beforeEach(function () {
        $this->unidadB = enCondominio($this->b, fn () => Unidad::factory()->create(['codigo' => 'B-101']));
        [, $this->tokenA] = usuarioConToken($this->a);
    });

    it('no lista unidades de otro condominio', function () {
        $this->withToken($this->tokenA)->withHeader('X-Condominio-Id', (string) $this->a->id)
            ->getJson('/api/v1/unidades')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('no ve, edita ni elimina una unidad de otro condominio', function () {
        $api = $this->withToken($this->tokenA)->withHeader('X-Condominio-Id', (string) $this->a->id);

        $api->getJson("/api/v1/unidades/{$this->unidadB->id}")->assertNotFound();
        $api->patchJson("/api/v1/unidades/{$this->unidadB->id}", ['piso' => 9])->assertNotFound();
        $api->deleteJson("/api/v1/unidades/{$this->unidadB->id}")->assertNotFound();

        expect(enCondominio($this->b, fn () => Unidad::find($this->unidadB->id)?->piso))->toBe(1);
    });

    it('permite el mismo código de unidad en condominios distintos', function () {
        $this->withToken($this->tokenA)->withHeader('X-Condominio-Id', (string) $this->a->id)
            ->postJson('/api/v1/unidades', ['codigo' => 'B-101', 'tipo' => 'casa', 'area_m2' => 90])
            ->assertCreated();
    });

    it('RLS filtra unidades aunque se salte el scope de Eloquent', function () {
        expect(enCondominio($this->a, fn () => DB::table('unidades')->count()))->toBe(0)
            ->and(enCondominio($this->b, fn () => DB::table('unidades')->count()))->toBe(1);
    });
});

describe('Personas y ocupantes', function () {
    beforeEach(function () {
        [$this->personaB, $this->unidadB] = enCondominio($this->b, fn () => [
            Persona::factory()->create(),
            Unidad::factory()->create(),
        ]);
        $this->ocupanteB = enCondominio($this->b, fn () => Ocupante::create([
            'unidad_id' => $this->unidadB->id, 'persona_id' => $this->personaB->id,
            'relacion' => 'propietario', 'fecha_inicio' => now()->subYear()->toDateString(),
        ]));
        [, $tokenA] = usuarioConToken($this->a);
        $this->apiA = fn () => $this->withToken($tokenA)->withHeader('X-Condominio-Id', (string) $this->a->id);
    });

    it('no lista, ve ni edita personas de otro condominio', function () {
        ($this->apiA)()->getJson('/api/v1/personas')->assertJsonCount(0, 'data');
        ($this->apiA)()->getJson("/api/v1/personas/{$this->personaB->id}")->assertNotFound();
        ($this->apiA)()->patchJson("/api/v1/personas/{$this->personaB->id}", ['nombres' => 'X'])->assertNotFound();
    });

    it('no asigna a una unidad propia una persona de otro condominio', function () {
        $unidadA = enCondominio($this->a, fn () => Unidad::factory()->create());

        ($this->apiA)()->postJson("/api/v1/unidades/{$unidadA->id}/ocupantes", [
            'persona_id' => $this->personaB->id, 'relacion' => 'propietario', 'fecha_inicio' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('persona_id', 'error.fields');
    });

    it('no ve ni finaliza ocupantes de otro condominio', function () {
        ($this->apiA)()->getJson("/api/v1/unidades/{$this->unidadB->id}/ocupantes")->assertNotFound();
        ($this->apiA)()->patchJson("/api/v1/ocupantes/{$this->ocupanteB->id}/finalizar", ['fecha_fin' => now()->toDateString()])->assertNotFound();
    });

    it('permite el mismo documento en condominios distintos', function () {
        $documento = enCondominio($this->b, fn () => Persona::find($this->personaB->id)->documento);

        ($this->apiA)()->postJson('/api/v1/personas', [
            'tipo_documento' => 'pasaporte', 'documento' => $documento, 'nombres' => 'Otra',
            'apellidos' => 'Persona', 'telefono' => '0991112233',
        ])->assertCreated();
    });

    it('RLS filtra personas y ocupantes aunque se salte el scope de Eloquent', function () {
        expect(enCondominio($this->a, fn () => DB::table('personas')->count() + DB::table('unidad_persona')->count()))->toBe(0)
            ->and(enCondominio($this->b, fn () => DB::table('unidad_persona')->count()))->toBe(1);
    });
});

describe('Vehículos y mascotas', function () {
    beforeEach(function () {
        [$this->vehiculoB, $this->mascotaB] = enCondominio($this->b, function () {
            $unidad = Unidad::factory()->create();

            return [
                Vehiculo::create(['unidad_id' => $unidad->id, 'placa' => 'PBA-1234', 'tipo' => 'auto']),
                Mascota::create(['unidad_id' => $unidad->id, 'nombre' => 'Luna', 'especie' => 'perro']),
            ];
        });
        $this->unidadB = $this->vehiculoB->unidad_id;
        [, $tokenA] = usuarioConToken($this->a);
        $this->apiA = fn () => $this->withToken($tokenA)->withHeader('X-Condominio-Id', (string) $this->a->id);
    });

    it('no edita ni quita vehículos o mascotas de otro condominio', function () {
        ($this->apiA)()->patchJson("/api/v1/vehiculos/{$this->vehiculoB->id}", ['color' => 'X'])->assertNotFound();
        ($this->apiA)()->deleteJson("/api/v1/vehiculos/{$this->vehiculoB->id}")->assertNotFound();
        ($this->apiA)()->patchJson("/api/v1/mascotas/{$this->mascotaB->id}", ['raza' => 'X'])->assertNotFound();
        ($this->apiA)()->deleteJson("/api/v1/mascotas/{$this->mascotaB->id}")->assertNotFound();
    });

    it('no registra en una unidad de otro condominio', function () {
        ($this->apiA)()->postJson("/api/v1/unidades/{$this->unidadB}/vehiculos", ['placa' => 'PBC-1111', 'tipo' => 'auto'])->assertNotFound();
        ($this->apiA)()->postJson("/api/v1/unidades/{$this->unidadB}/mascotas", ['nombre' => 'X', 'especie' => 'gato'])->assertNotFound();
    });

    it('la misma placa puede estar en condominios distintos', function () {
        $unidadA = enCondominio($this->a, fn () => Unidad::factory()->create());

        ($this->apiA)()->postJson("/api/v1/unidades/{$unidadA->id}/vehiculos", ['placa' => 'PBA-1234', 'tipo' => 'auto'])->assertCreated();
    });

    it('RLS filtra vehículos y mascotas aunque se salte el scope de Eloquent', function () {
        expect(enCondominio($this->a, fn () => DB::table('vehiculos')->count() + DB::table('mascotas')->count()))->toBe(0)
            ->and(enCondominio($this->b, fn () => DB::table('vehiculos')->count() + DB::table('mascotas')->count()))->toBe(2);
    });
});
