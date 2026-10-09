<?php

use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Plataforma\Models\Plan;
use App\Modules\Usuarios\Mail\SolicitudRolMail;
use App\Modules\Usuarios\Models\SolicitudRol;
use Database\Seeders\MenuSeeder;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Mail::fake();
    $this->seed(MenuSeeder::class);

    $plan = Plan::create(['codigo' => 'profesional', 'nombre' => 'Profesional', 'limite_administrativos' => 3, 'valor_unidad_sugerido' => '2.00', 'orden' => 2, 'activo' => true]);
    $this->condominio = Condominio::factory()->create(['plan_id' => $plan->id]);
    [$this->admin, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $this->roles = fn () => collect(($this->api)()->getJson('/api/v1/roles')->assertOk()->json('data.roles'))->keyBy('clave');
});

it('lista los roles del condominio en orden: sistema, cargos y adicionales', function () {
    $r = ($this->api)()->getJson('/api/v1/roles')->assertOk();

    expect(collect($r->json('data.roles'))->pluck('clave')->all())->toBe([
        'administrador', 'contador', 'guardia', 'mantenimiento', 'residente',
        'presidente', 'vicepresidente', 'secretario', 'tesorero',
    ])
        ->and(collect($r->json('data.roles'))->pluck('tipo')->unique()->values()->all())->toBe(['sistema', 'cargo'])
        ->and($r->json('data.roles.0.nombre'))->toBe('Administrador');
});

it('no muestra roles ni permisos de la plataforma', function () {
    $r = ($this->api)()->getJson('/api/v1/roles')->assertOk();

    expect(collect($r->json('data.roles'))->pluck('clave'))->not->toContain('super_admin')->not->toContain('soporte')
        ->and(collect($r->json('data.permisos'))->pluck('clave')->filter(fn ($c) => str_starts_with($c, 'plataforma.'))->all())->toBe([])
        ->and(json_encode($r->json('data.roles')))->not->toContain('plataforma.');
});

it('cada rol dice qué permite y el catálogo de permisos trae etiqueta, grupo y si es administrativo', function () {
    $roles = ($this->roles)();
    $catalogo = collect(($this->api)()->getJson('/api/v1/roles')->json('data.permisos'))->keyBy('clave');

    expect($roles['administrador']['permisos'])->toContain('unidades.editar', 'usuarios.gestionar', 'condominio.editar', 'garita.directorio')
        ->and($roles['guardia']['permisos'])->toBe(['garita.directorio', 'unidades.ver'])
        ->and($roles['residente']['permisos'])->toBe([])
        ->and($catalogo['garita.directorio'])->toBe(['clave' => 'garita.directorio', 'etiqueta' => 'Consultar el directorio de garita', 'grupo' => 'Garita', 'administrativo' => false])
        ->and($catalogo['unidades.editar'])->toMatchArray(['grupo' => 'Núcleo', 'administrativo' => true]);
});

it('indica qué roles consumen cupo del plan', function () {
    $roles = ($this->roles)();

    expect($roles['administrador']['cuenta_cupo'])->toBeTrue()
        ->and($roles['contador']['cuenta_cupo'])->toBeTrue()
        ->and($roles['tesorero']['cuenta_cupo'])->toBeTrue()
        ->and(collect(['guardia', 'mantenimiento', 'residente', 'presidente', 'vicepresidente', 'secretario'])->every(fn ($c) => $roles[$c]['cuenta_cupo'] === false))->toBeTrue();

    ($this->api)()->getJson('/api/v1/roles')->assertJsonPath('data.cupo', ['plan' => 'Profesional', 'limite' => 3, 'usados' => 1]);
});

it('el menú de cada rol sale de sus permisos y de las hojas asignadas', function () {
    $roles = ($this->roles)();
    $etiquetas = fn (string $clave) => collect($roles[$clave]['menu'])->pluck('etiqueta')->all();

    expect($etiquetas('administrador'))->toBe(['Inicio', 'Unidades', 'Bloques', 'Datos del condominio', 'Cobro de cuotas', 'Amenidades', 'Usuarios', 'Roles'])
        ->and($etiquetas('guardia'))->toBe(['Inicio', 'Unidades', 'Bloques'])
        ->and($etiquetas('residente'))->toBe(['Inicio'])
        ->and($roles['administrador']['menu'][1])->toBe(['etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment']);
});

it('cuenta cuántas personas tienen cada rol en este condominio y no en otros', function () {
    User::factory()->count(2)->miembroDe($this->condominio, Rol::Guardia, false)->create();
    $otro = Condominio::factory()->create();
    User::factory()->count(3)->miembroDe($otro, Rol::Guardia)->create();

    $roles = ($this->roles)();

    expect($roles['administrador']['usuarios'])->toBe(1)
        ->and($roles['guardia']['usuarios'])->toBe(2)
        ->and($roles['contador']['usuarios'])->toBe(0);
});

it('un rol adicional creado por la plataforma aparece al final, con cupo si es administrativo', function () {
    $conserje = Role::create(['name' => 'conserje_nocturno', 'guard_name' => 'api', 'condominio_id' => null]);
    $conserje->givePermissionTo(Permission::findByName('unidades.ver', 'api'));
    $asistente = Role::create(['name' => 'asistente_contable', 'guard_name' => 'api', 'condominio_id' => null]);
    $asistente->givePermissionTo(Permission::findByName('unidades.editar', 'api'));

    $r = ($this->api)()->getJson('/api/v1/roles')->assertOk();
    $roles = collect($r->json('data.roles'))->keyBy('clave');

    expect(collect($r->json('data.roles'))->pluck('clave')->slice(-2)->values()->all())->toBe(['asistente_contable', 'conserje_nocturno'])
        ->and($roles['conserje_nocturno'])->toMatchArray(['nombre' => 'Conserje Nocturno', 'tipo' => 'adicional', 'cuenta_cupo' => false])
        ->and($roles['asistente_contable'])->toMatchArray(['tipo' => 'adicional', 'cuenta_cupo' => true]);
});

it('registra la solicitud de un rol nuevo y avisa al soporte', function () {
    config(['safic.soporte_email' => 'soporte@safic.ec']);

    ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'Jardinero', 'descripcion' => 'Ver la agenda de áreas y reportar incidencias de áreas verdes'])
        ->assertCreated()
        ->assertJsonPath('message', 'Solicitud enviada a la plataforma.')
        ->assertJsonPath('data.estado', 'pendiente');

    $solicitud = enCondominio($this->condominio, fn () => SolicitudRol::sole());
    expect($solicitud->nombre)->toBe('Jardinero')->and($solicitud->user_id)->toBe($this->admin->id)->and($solicitud->condominio_id)->toBe($this->condominio->id);
    Mail::assertQueued(SolicitudRolMail::class, fn ($m) => $m->hasTo('soporte@safic.ec') && $m->nombre === 'Jardinero' && $m->condominio === $this->condominio->nombre);
});

it('sin correo de soporte la solicitud igual queda registrada', function () {
    config(['safic.soporte_email' => null]);

    ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'Jardinero', 'descripcion' => 'Reportar incidencias de áreas verdes'])->assertCreated();

    expect(enCondominio($this->condominio, fn () => SolicitudRol::count()))->toBe(1);
    Mail::assertNothingQueued();
});

it('valida la solicitud', function () {
    $r = ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'ab', 'descripcion' => 'corto'])->assertStatus(422);
    expect($r->json('error.fields.nombre.0'))->toBe('El nombre tiene mínimo 3 caracteres.')
        ->and($r->json('error.fields.descripcion.0'))->toBe('Cuéntanos un poco más: mínimo 10 caracteres.');

    ($this->api)()->postJson('/api/v1/roles/solicitudes', [])->assertStatus(422)
        ->assertJsonPath('error.fields.nombre.0', 'Escribe el nombre sugerido para el rol.');
    ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => str_repeat('a', 61), 'descripcion' => str_repeat('b', 501)])->assertStatus(422);
    expect(enCondominio($this->condominio, fn () => SolicitudRol::count()))->toBe(0);
});

it('limita las solicitudes a 5 por hora', function () {
    foreach (range(1, 5) as $i) {
        ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => "Rol $i", 'descripcion' => 'Una descripción suficientemente larga'])->assertCreated();
    }

    ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'Rol 6', 'descripcion' => 'Una descripción suficientemente larga'])->assertStatus(429);
});

it('las solicitudes de un condominio no se ven en otro', function () {
    ($this->api)()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'Jardinero', 'descripcion' => 'Reportar incidencias de áreas verdes'])->assertCreated();
    $otro = Condominio::factory()->create();

    expect(enCondominio($otro, fn () => SolicitudRol::count()))->toBe(0);
});

it('solo quien gestiona usuarios ve los roles y pide uno nuevo', function () {
    foreach ([Rol::Guardia, Rol::Residente, Rol::Contador] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        cambiarDeUsuario();
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/roles')->assertForbidden();
        $api()->postJson('/api/v1/roles/solicitudes', ['nombre' => 'Jardinero', 'descripcion' => 'Reportar incidencias de áreas verdes'])->assertForbidden();
    }
});
