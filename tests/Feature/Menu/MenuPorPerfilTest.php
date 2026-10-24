<?php

use App\Core\Menu\Models\MenuItem;
use App\Core\Permissions\Permiso;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\MenuSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Cada perfil ve su menú. Una hoja aparece solo si está asignada a uno de los
| perfiles del usuario en el equipo activo Y el usuario tiene su permiso.
*/

function menuDe(string $token, Condominio $condominio): array
{
    app('auth')->forgetGuards();

    return test()->withToken($token)
        ->withHeader('X-Condominio-Id', (string) $condominio->id)
        ->getJson('/api/v1/me/menu')
        ->assertOk()
        ->json('data');
}

function rolGlobal(Rol $rol): Role
{
    return Role::query()->whereNull('condominio_id')->where('name', $rol->value)->firstOrFail();
}

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->condominio = Condominio::factory()->create();
});

it('el administrador ve inicio, unidades con bloques y su configuración', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(menuDe($token, $this->condominio))->toBe([
        ['id' => 'inicio', 'etiqueta' => 'Inicio', 'icono' => 'sym_r_space_dashboard', 'ruta' => 'inicio'],
        ['id' => 'unidades', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'hijos' => [
            ['id' => 'unidades.lista', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'ruta' => 'unidades', 'permiso' => 'unidades.ver'],
            ['id' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => 'unidades.ver'],
        ]],
        ['id' => 'finanzas', 'etiqueta' => 'Finanzas', 'icono' => 'sym_r_account_balance_wallet', 'hijos' => [
            ['id' => 'finanzas.resumen', 'etiqueta' => 'Resumen', 'icono' => 'sym_r_dashboard', 'ruta' => 'finanzas-resumen', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.cuotas', 'etiqueta' => 'Cuotas del mes', 'icono' => 'sym_r_receipt_long', 'ruta' => 'finanzas-cuotas', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.cierre', 'etiqueta' => 'Cierre de mes', 'icono' => 'sym_r_lock', 'ruta' => 'finanzas-cierre-mes', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.conciliacion', 'etiqueta' => 'Conciliación bancaria', 'icono' => 'sym_r_account_balance', 'ruta' => 'finanzas-conciliacion', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.morosidad', 'etiqueta' => 'Morosidad', 'icono' => 'sym_r_notifications_active', 'ruta' => 'finanzas-morosidad', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.reportes', 'etiqueta' => 'Reportes', 'icono' => 'sym_r_bar_chart', 'ruta' => 'finanzas-reportes', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.pagos', 'etiqueta' => 'Pagos por aprobar', 'icono' => 'sym_r_fact_check', 'ruta' => 'finanzas-pagos-por-aprobar', 'permiso' => 'pagos.aprobar'],
            ['id' => 'finanzas.cuentas', 'etiqueta' => 'Cuentas por pagar', 'icono' => 'sym_r_payments', 'ruta' => 'finanzas-cuentas-por-pagar', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.recurrentes', 'etiqueta' => 'Gastos recurrentes', 'icono' => 'sym_r_autorenew', 'ruta' => 'finanzas-recurrentes', 'permiso' => 'finanzas.ver'],
            ['id' => 'finanzas.caja-chica', 'etiqueta' => 'Caja chica', 'icono' => 'sym_r_savings', 'ruta' => 'finanzas-caja-chica', 'permiso' => 'cajachica.registrar'],
            ['id' => 'finanzas.proveedores', 'etiqueta' => 'Proveedores', 'icono' => 'sym_r_local_shipping', 'ruta' => 'finanzas-proveedores', 'permiso' => 'finanzas.ver'],
        ]],
        ['id' => 'areas', 'etiqueta' => 'Áreas comunes', 'icono' => 'sym_r_pool', 'hijos' => [
            ['id' => 'areas.agenda', 'etiqueta' => 'Agenda', 'icono' => 'sym_r_calendar_month', 'ruta' => 'areas-agenda', 'permiso' => 'reservas.gestionar'],
            ['id' => 'areas.reglas', 'etiqueta' => 'Áreas y reglas', 'icono' => 'sym_r_rule', 'ruta' => 'areas-reglas', 'permiso' => 'reservas.gestionar'],
        ]],
        ['id' => 'seguridad', 'etiqueta' => 'Seguridad y comunicación', 'icono' => 'sym_r_shield_person', 'hijos' => [
            ['id' => 'seguridad.bitacora', 'etiqueta' => 'Bitácora de garita', 'icono' => 'sym_r_badge', 'ruta' => 'garita-bitacora', 'permiso' => 'garita.bitacora'],
            ['id' => 'seguridad.anuncios', 'etiqueta' => 'Anuncios', 'icono' => 'sym_r_campaign', 'ruta' => 'comunicacion-anuncios', 'permiso' => 'anuncios.publicar'],
            ['id' => 'seguridad.incidencias', 'etiqueta' => 'Incidencias', 'icono' => 'sym_r_support_agent', 'ruta' => 'comunicacion-incidencias', 'permiso' => 'incidencias.gestionar'],
        ]],
        ['id' => 'asambleas', 'etiqueta' => 'Asambleas', 'icono' => 'sym_r_how_to_vote', 'hijos' => [
            ['id' => 'asambleas.preparar', 'etiqueta' => 'Preparar asamblea', 'icono' => 'sym_r_edit_calendar', 'ruta' => 'asambleas-preparar', 'permiso' => 'asambleas.preparar'],
            ['id' => 'asambleas.registro', 'etiqueta' => 'Registro de asistencia', 'icono' => 'sym_r_how_to_reg', 'ruta' => 'asambleas-registro', 'permiso' => 'asambleas.registrar'],
        ]],
        ['id' => 'configuracion', 'etiqueta' => 'Configuración', 'icono' => 'sym_r_settings', 'seccion' => true, 'hijos' => [
            ['id' => 'configuracion.condominio', 'etiqueta' => 'Datos del condominio', 'icono' => 'sym_r_domain', 'ruta' => 'configuracion-condominio', 'permiso' => 'condominio.editar'],
            ['id' => 'configuracion.cobro', 'etiqueta' => 'Cobro de cuotas', 'icono' => 'sym_r_request_quote', 'ruta' => 'configuracion-cobro', 'permiso' => 'condominio.editar'],
            ['id' => 'configuracion.amenidades', 'etiqueta' => 'Amenidades', 'icono' => 'sym_r_pool', 'ruta' => 'configuracion-amenidades', 'permiso' => 'amenidades.gestionar'],
            ['id' => 'configuracion.usuarios', 'etiqueta' => 'Usuarios', 'icono' => 'sym_r_manage_accounts', 'ruta' => 'configuracion-usuarios', 'permiso' => 'usuarios.gestionar'],
            ['id' => 'configuracion.roles', 'etiqueta' => 'Roles', 'icono' => 'sym_r_admin_panel_settings', 'ruta' => 'configuracion-roles', 'permiso' => 'usuarios.gestionar'],
        ]],
    ]);
});

it('ninguna pantalla en vista previa llega al menú de producción', function () {
    // Solo rutas que ya tienen pantalla con API; una vista previa se suma cuando pasa a datos reales
    $rutas = MenuItem::query()->whereNotNull('ruta')->pluck('ruta')->sort()->values()->all();

    expect($rutas)->toBe(['areas-agenda', 'areas-reglas', 'asambleas-preparar', 'asambleas-registro', 'bloques', 'comunicacion-anuncios', 'comunicacion-incidencias', 'configuracion-amenidades', 'configuracion-cobro', 'configuracion-condominio', 'configuracion-roles', 'configuracion-usuarios', 'finanzas-aprobaciones', 'finanzas-caja-chica', 'finanzas-cierre-mes', 'finanzas-conciliacion', 'finanzas-cuentas-por-pagar', 'finanzas-cuotas', 'finanzas-morosidad', 'finanzas-pagos-por-aprobar', 'finanzas-proveedores', 'finanzas-recurrentes', 'finanzas-reportes', 'finanzas-resumen', 'garita-bitacora', 'inicio', 'plataforma-amenidades', 'plataforma-condominios', 'plataforma-menu', 'plataforma-roles', 'unidades']);
});

it('un perfil sin permisos de configuración no ve esa sección', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->not->toContain('configuracion');
});

it('el residente solo ve inicio', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Residente);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('oculta una hoja asignada si al usuario le falta el permiso', function () {
    rolGlobal(Rol::Administrador)->revokePermissionTo(Permiso::UnidadesVer->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'finanzas', 'areas', 'seguridad', 'asambleas', 'configuracion']);
});

it('oculta una hoja no asignada al perfil aunque tenga el permiso', function () {
    MenuItem::query()->whereIn('clave', ['unidades.lista', 'unidades.bloques'])->get()
        ->each(fn (MenuItem $item) => $item->roles()->detach(rolGlobal(Rol::Guardia)->id));
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio']);
});

it('oculta un módulo inactivo con sus hojas', function () {
    MenuItem::query()->where('clave', 'unidades')->update(['activo' => false]);
    [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'finanzas', 'areas', 'seguridad', 'asambleas', 'configuracion']);
});

it('arma el menú con el perfil que el usuario tiene en el condominio del header', function () {
    $otro = Condominio::factory()->create();
    [$user, $token] = usuarioConToken($this->condominio, Rol::Administrador);
    $user->membresias()->create(['condominio_id' => $otro->id, 'es_principal' => false, 'activo' => true]);
    setPermissionsTeamId($otro->id);
    $user->unsetRelation('roles')->assignRole(Rol::Residente->value);
    setPermissionsTeamId(null);

    expect(array_column(menuDe($token, $this->condominio), 'id'))->toBe(['inicio', 'unidades', 'finanzas', 'areas', 'seguridad', 'asambleas', 'configuracion'])
        ->and(array_column(menuDe($token, $otro), 'id'))->toBe(['inicio']);
});

it('un usuario sin perfil en el condominio recibe un menú vacío', function () {
    [, $token] = usuarioConToken($this->condominio, null);

    expect(menuDe($token, $this->condominio))->toBe([]);
});

describe('menú de plataforma', function () {
    beforeEach(function () {
        // Condominios viene sembrado para los perfiles que tienen su permiso (super admin y soporte)
        $this->itemPlataforma = MenuItem::query()->where('clave', 'plataforma.condominios')->firstOrFail();
    });

    it('el super admin ve su menú de plataforma', function () {
        $user = User::factory()->dePlataforma()->create();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'plataforma.condominios');
    });

    it('soporte ve Condominios por defecto y no lo ve si se le quitan las hojas', function () {
        $user = User::factory()->dePlataforma(Rol::Soporte)->create();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'plataforma.condominios');

        // Se le quitan todas las hojas de plataforma (Condominios y Catálogo de amenidades)
        MenuItem::query()->where('ambito', MenuItem::AMBITO_PLATAFORMA)->get()
            ->each(fn (MenuItem $item) => $item->roles()->detach(rolGlobal(Rol::Soporte)->id));
        app('auth')->forgetGuards();
        app('tymon.jwt')->unsetToken();

        $this->withToken(auth('api')->tokenById($user->id))
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });

    it('el menú del condominio no incluye ítems de plataforma', function () {
        [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

        expect(array_column(menuDe($token, $this->condominio), 'id'))->not->toContain('plataforma.condominios');
    });

    it('un usuario de condominio no pide el menú de plataforma', function () {
        [, $token] = usuarioConToken($this->condominio, Rol::Administrador);

        $this->withToken($token)
            ->getJson('/api/v1/plataforma/me/menu')
            ->assertForbidden();
    });
});
