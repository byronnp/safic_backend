<?php

use App\Core\Audit\RegistroBitacora;
use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function tokenBitacora(Rol $rol = Rol::SuperAdmin, string $nombre = 'Ana Súper'): string
{
    return auth('api')->tokenById(User::factory()->dePlataforma($rol)->create(['name' => $nombre])->id);
}

function permisosBitacora(string $clave): array
{
    return Role::query()->whereNull('condominio_id')->where('name', $clave)->firstOrFail()->permissions->pluck('name')->sort()->values()->all();
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(MenuSeeder::class);
    $this->api = fn () => $this->withToken(tokenBitacora());
});

it('registra quién agregó un ítem del menú, con sus valores', function () {
    $grupo = MenuItem::query()->where('clave', 'configuracion')->firstOrFail();

    ($this->api)()->postJson('/api/v1/plataforma/menu-sistema', [
        'ambito' => 'condominio', 'padre_id' => $grupo->id, 'etiqueta' => 'Reglamento', 'icono' => 'sym_r_gavel', 'ruta' => 'reglamento', 'roles' => ['administrador'],
    ])->assertCreated();

    $creado = RegistroBitacora::query()->where('entidad', 'menu')->where('evento', 'creado')->firstOrFail();
    $perfiles = RegistroBitacora::query()->where('entidad', 'menu')->where('evento', 'actualizado')->firstOrFail();

    expect($creado->user_nombre)->toBe('Ana Súper')
        ->and($creado->etiqueta)->toBe('condominio: Reglamento')
        ->and($creado->valores_nuevos)->toMatchArray(['ruta' => 'reglamento'])
        ->and($perfiles->valores_nuevos)->toBe(['perfiles' => ['administrador']]);
});

it('en una edición guarda solo lo que cambió, antes y después', function () {
    $item = MenuItem::query()->where('clave', 'inicio')->firstOrFail();

    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$item->id}", ['etiqueta' => 'Panel'])->assertOk();

    $r = RegistroBitacora::query()->where('evento', 'actualizado')->firstOrFail();
    expect($r->valores_anteriores)->toBe(['etiqueta' => 'Inicio'])->and($r->valores_nuevos)->toBe(['etiqueta' => 'Panel']);
});

it('no registra cambios que no cambian nada ni lo que hace el sistema sin una persona', function () {
    $item = MenuItem::query()->where('clave', 'inicio')->firstOrFail();
    $item->update(['etiqueta' => 'Inicio']);              // sin cambios
    $item->update(['etiqueta' => 'Otro']);                // sin sesión

    expect(RegistroBitacora::query()->count())->toBe(0);
});

it('registra los permisos de un rol antes y después', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => ['unidades.ver']])->assertOk();

    $r = RegistroBitacora::query()->where('entidad', 'rol')->firstOrFail();
    expect($r->entidad_id)->toBe('guardia')
        ->and($r->valores_anteriores)->toBe(['permisos' => ['garita.directorio', 'unidades.ver']])
        ->and($r->valores_nuevos)->toBe(['permisos' => ['unidades.ver']]);
});

it('un cambio rechazado no deja registro', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/contador/permisos', ['permisos' => ['unidades.editar']])->assertStatus(422);

    expect(RegistroBitacora::query()->count())->toBe(0);
});

it('registra el catálogo de amenidades', function () {
    ($this->api)()->postJson('/api/v1/plataforma/catalogo-amenidades', ['nombre' => 'Sala de cine', 'categoria' => 'recreacion', 'reservable' => true])->assertCreated();
    $tipo = AmenidadCatalogo::query()->where('nombre', 'Sala de cine')->firstOrFail();
    ($this->api)()->deleteJson("/api/v1/plataforma/catalogo-amenidades/{$tipo->id}")->assertSuccessful();

    expect(RegistroBitacora::query()->where('entidad', 'catalogo_amenidad')->pluck('evento')->sort()->values()->all())->toBe(['creado', 'eliminado']);

});

it('registra los datos del condominio sin datos personales', function () {
    $condominio = Condominio::factory()->create();
    [, $token] = usuarioConToken($condominio, Rol::Administrador);
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->patchJson('/api/v1/condominio', ['nombre' => 'Brisas Nuevas', 'telefono' => '0991234567'])->assertOk();

    $r = RegistroBitacora::query()->where('entidad', 'condominio')->where('evento', 'actualizado')->latest('id')->firstOrFail();
    expect($r->condominio_id)->toBe($condominio->id)
        ->and($r->valores_nuevos)->toHaveKey('nombre')->not->toHaveKey('telefono');
});

it('la aplicación no puede modificar ni borrar la bitácora', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Los permisos por tabla solo existen en PostgreSQL.');
    }
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => []])->assertOk();
    $id = RegistroBitacora::query()->firstOrFail()->id;

    expect(fn () => DB::table('bitacora_plataforma')->where('id', $id)->delete())->toThrow(Exception::class)
        ->and(fn () => DB::table('bitacora_plataforma')->where('id', $id)->update(['evento' => 'x']))->toThrow(Exception::class);
});

it('el super admin consulta la bitácora con filtros y paginación', function () {
    ($this->api)()->putJson('/api/v1/plataforma/roles/guardia/permisos', ['permisos' => []])->assertOk();
    ($this->api)()->putJson('/api/v1/plataforma/roles/residente/permisos', ['permisos' => []])->assertOk();
    $item = MenuItem::query()->where('clave', 'inicio')->firstOrFail();
    ($this->api)()->patchJson("/api/v1/plataforma/menu-sistema/{$item->id}", ['etiqueta' => 'Panel'])->assertOk();

    $todos = ($this->api)()->getJson('/api/v1/plataforma/bitacora')->assertOk();
    expect($todos->json('meta.pagination.total'))->toBe(3)
        ->and($todos->json('data.0'))->toHaveKeys(['id', 'fecha', 'usuario', 'evento', 'entidad', 'etiqueta', 'antes', 'despues']);

    expect(($this->api)()->getJson('/api/v1/plataforma/bitacora?entidad=menu')->json('meta.pagination.total'))->toBe(1)
        ->and(($this->api)()->getJson('/api/v1/plataforma/bitacora?buscar=guardia')->json('meta.pagination.total'))->toBe(1)
        ->and(($this->api)()->getJson('/api/v1/plataforma/bitacora?por_pagina=1')->json('data'))->toHaveCount(1);

    ($this->api)()->getJson('/api/v1/plataforma/bitacora?entidad=otra')->assertStatus(422);
    ($this->api)()->getJson('/api/v1/plataforma/bitacora?desde=2026-02-01&hasta=2026-01-01')->assertStatus(422);
});

it('solo quien tiene permiso de auditoría la consulta', function () {
    $this->withToken(tokenBitacora(Rol::Soporte))->getJson('/api/v1/plataforma/bitacora')->assertForbidden();
});

it('un administrador de condominio no la consulta', function () {
    [, $token] = usuarioConToken(Condominio::factory()->create(), Rol::Administrador);
    $this->withToken($token)->getJson('/api/v1/plataforma/bitacora')->assertForbidden();
});

it('un permiso nuevo llega al super admin existente sin tocar a los demás roles', function () {
    $super = Role::query()->whereNull('condominio_id')->where('name', 'super_admin')->firstOrFail();
    $super->revokePermissionTo('plataforma.auditoria');
    Permission::query()->where('name', 'plataforma.auditoria')->delete();
    Role::query()->whereNull('condominio_id')->where('name', 'guardia')->firstOrFail()->syncPermissions([]);

    $this->seed(RolesYPermisosSeeder::class);

    expect(permisosBitacora('super_admin'))->toContain('plataforma.auditoria')
        ->and(permisosBitacora('guardia'))->toBe([]);
});
