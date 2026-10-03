<?php

use App\Core\Auth\Models\Invitacion;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Models\CobroValorTipo;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Plataforma\Mail\InvitacionAdministradorMail;
use App\Modules\Plataforma\Models\AmenidadCatalogo;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Mail;

/*
| Alta de condominio desde el asistente del super admin (S1).
*/

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosCondominio(array $cambios = []): array
{
    $piscina = AmenidadCatalogo::where('nombre', 'Piscina')->value('id');
    $bbq = AmenidadCatalogo::where('nombre', 'Área BBQ')->value('id');

    $datos = array_replace_recursive([
        'nombre' => 'Conjunto Los Arupos',
        'tipo' => 'conjunto',
        'ruc' => '1792456781001',
        'razon_social' => 'Conjunto Habitacional Los Arupos',
        'provincia_codigo' => '17',
        'canton_codigo' => '1701',
        'parroquia_codigo' => '170156',
        'direccion' => 'Av. Ilaló y calle Los Arupos',
        'telefono' => '022345678',
        'email_contacto' => 'admin@losarupos.ec',
        'total_unidades' => 130,
        'plan_codigo' => 'profesional',
        'valor_unidad' => '2.00',
        'latitud' => -0.285412,
        'longitud' => -78.471236,
        'cobro' => [
            'metodo' => 'general',
            'cuota_general' => '80.00',
            'dia_vencimiento' => 10,
            'primera_cuota' => now()->addMonth()->format('Y-m'),
        ],
        'amenidades' => [
            ['amenidad_id' => $piscina, 'cantidad' => 1],
            ['amenidad_id' => $bbq, 'cantidad' => 2],
        ],
        'administrador' => [
            'cedula' => '1712345675',
            'nombre' => 'María Rivas',
            'email' => 'Maria.Rivas@LosArupos.ec',
            'celular' => '0994127788',
        ],
    ], $cambios);

    // array_replace_recursive no vacía listas: una lista nueva reemplaza a la anterior
    foreach (['amenidades'] as $lista) {
        if (array_key_exists($lista, $cambios)) {
            $datos[$lista] = $cambios[$lista];
        }
    }

    return $datos;
}

function tokenDe(User $user): string
{
    return auth('api')->tokenById($user->id);
}

beforeEach(function () {
    sembrarCatalogos();
    Mail::fake();
    $this->superAdmin = User::factory()->dePlataforma()->create();
});

it('crea el condominio completo e invita al administrador nuevo', function () {
    $respuesta = $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio())
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Conjunto Los Arupos')
        ->assertJsonPath('data.estado', 'prueba')
        ->assertJsonPath('data.plan.codigo', 'profesional')
        ->assertJsonPath('data.mensualidad', '260.00')
        ->assertJsonPath('data.ubicacion.parroquia', 'Conocoto')
        ->assertJsonPath('data.administradores.0.estado', 'invitado')
        ->assertJsonPath('meta.invitacion_enviada', true);

    $condominio = Condominio::findOrFail($respuesta->json('data.id'));
    expect($condominio->codigo)->toMatch('/^SF-\d{4}$/')
        ->and($condominio->prueba_hasta->toDateString())->toBe(now()->addDays(30)->toDateString());

    $cobro = enCondominio($condominio, fn () => ConfiguracionCobro::sole());
    expect($cobro->metodo)->toBe('general')
        ->and($cobro->cuota_general)->toBe('80.00')
        ->and($cobro->aplica_desde->toDateString())->toBe(now()->addMonth()->startOfMonth()->toDateString());

    $amenidades = enCondominio($condominio, fn () => CondominioAmenidad::orderBy('nombre')->get());
    expect($amenidades->pluck('nombre')->all())->toBe(['Área BBQ', 'Piscina'])
        ->and($amenidades->firstWhere('nombre', 'Área BBQ')->cantidad)->toBe(2)
        ->and($amenidades->firstWhere('nombre', 'Piscina')->reservable)->toBeTrue();

    $admin = User::where('email', 'maria.rivas@losarupos.ec')->sole();
    expect($admin->activo)->toBeFalse()
        ->and($admin->cedula)->toBe('1712345675')
        ->and($admin->tieneMembresiaActivaEn($condominio->id))->toBeTrue();

    setPermissionsTeamId($condominio->id);
    expect($admin->fresh()->hasRole(Rol::Administrador->value))->toBeTrue();
    setPermissionsTeamId(null);

    expect(Invitacion::where('user_id', $admin->id)->sole()->vigente())->toBeTrue();
    Mail::assertQueued(InvitacionAdministradorMail::class, fn ($mail) => $mail->hasTo('maria.rivas@losarupos.ec')
        && str_starts_with($mail->enlace, config('safic.frontend_url').'/invitacion/'));
});

it('asocia a un administrador que ya tiene cuenta sin invitarlo', function () {
    $otro = Condominio::factory()->create();
    $existente = User::factory()->miembroDe($otro, Rol::Administrador)->create(['email' => 'maria.rivas@losarupos.ec']);

    $respuesta = $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio())
        ->assertCreated()
        ->assertJsonPath('meta.administrador_existente', true)
        ->assertJsonPath('data.administradores.0.estado', 'activo');

    $nuevo = $respuesta->json('data.id');
    expect($existente->fresh()->tieneMembresiaActivaEn($nuevo))->toBeTrue()
        ->and($existente->membresias()->where('condominio_id', $nuevo)->value('es_principal'))->toBeFalse()
        ->and(Invitacion::count())->toBe(0);

    Mail::assertNothingQueued();
});

it('guarda los valores por tipo cuando el método es por tipo', function () {
    $respuesta = $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio(['cobro' => [
            'metodo' => 'tipo',
            'cuota_general' => null,
            'valores_tipo' => [['tipo' => 'departamento', 'valor' => '80.00'], ['tipo' => 'casa', 'valor' => '120.50']],
        ]]))
        ->assertCreated();

    $condominio = Condominio::findOrFail($respuesta->json('data.id'));
    $valores = enCondominio($condominio, fn () => CobroValorTipo::orderBy('tipo_unidad')->pluck('valor', 'tipo_unidad')->all());

    expect($valores)->toBe(['casa' => '120.50', 'departamento' => '80.00'])
        ->and(enCondominio($condominio, fn () => ConfiguracionCobro::sole()->cuota_general))->toBeNull();
});

it('asigna códigos correlativos', function () {
    Condominio::factory()->create(['codigo' => 'SF-0041']);

    $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio())
        ->assertCreated()
        ->assertJsonPath('data.codigo', 'SF-0042');
});

it('valida cada paso del asistente', function (array $cambios, string $campo) {
    $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio($cambios))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDACION')
        ->assertJsonValidationErrors($campo, 'error.fields');
})->with([
    'RUC inválido' => [['ruc' => '1792456781000'], 'ruc'],
    'cantón de otra provincia' => [['canton_codigo' => '0901'], 'canton_codigo'],
    'parroquia de otro cantón' => [['parroquia_codigo' => '010101'], 'parroquia_codigo'],
    'sin unidades' => [['total_unidades' => 0], 'total_unidades'],
    'valor con 3 decimales' => [['valor_unidad' => '2.005'], 'valor_unidad'],
    'fuera del Ecuador' => [['latitud' => 40.4], 'latitud'],
    'valor general sin cuota' => [['cobro' => ['cuota_general' => null]], 'cobro.cuota_general'],
    'primera cuota pasada' => [['cobro' => ['primera_cuota' => now()->subMonth()->format('Y-m')]], 'cobro.primera_cuota'],
    'método desconocido' => [['cobro' => ['metodo' => 'otro']], 'cobro.metodo'],
    'amenidad inexistente' => [['amenidades' => [['amenidad_id' => 99999, 'cantidad' => 1]]], 'amenidades.0.amenidad_id'],
    'cédula inválida' => [['administrador' => ['cedula' => '1712345678']], 'administrador.cedula'],
    'celular inválido' => [['administrador' => ['celular' => '022345678']], 'administrador.celular'],
]);

it('no repite el RUC en la plataforma', function () {
    Condominio::factory()->create(['ruc' => '1792456781001']);

    $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ruc', 'error.fields');
});

it('no deja nada a medias si la cédula es de otra persona', function () {
    User::factory()->create(['email' => 'otra@correo.ec', 'cedula' => '1712345675']);

    $this->withToken(tokenDe($this->superAdmin))
        ->postJson('/api/v1/plataforma/condominios', datosCondominio())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'CEDULA_EN_USO');

    expect(Condominio::where('ruc', '1792456781001')->exists())->toBeFalse()
        ->and(User::where('email', 'maria.rivas@losarupos.ec')->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

describe('permisos de plataforma', function () {
    it('rechaza a un administrador de condominio', function () {
        $condominio = Condominio::factory()->create();
        $admin = User::factory()->miembroDe($condominio, Rol::Administrador)->create();

        $this->withToken(tokenDe($admin))
            ->postJson('/api/v1/plataforma/condominios', datosCondominio())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SIN_PERMISO');
    });

    it('rechaza a cobranza (no tiene plataforma.condominios)', function () {
        $cobranza = User::factory()->dePlataforma(Rol::Cobranza)->create();

        $this->withToken(tokenDe($cobranza))
            ->getJson('/api/v1/plataforma/condominios')
            ->assertForbidden();
    });

    it('permite a soporte ver los condominios', function () {
        $soporte = User::factory()->dePlataforma(Rol::Soporte)->create();

        $this->withToken(tokenDe($soporte))->getJson('/api/v1/plataforma/condominios')->assertOk();
    });

    it('exige sesión', function () {
        $this->postJson('/api/v1/plataforma/condominios', datosCondominio())->assertUnauthorized();
    });
});

describe('aislamiento de los datos del alta', function () {
    it('la configuración de cobro y las amenidades quedan en su condominio', function () {
        $token = tokenDe($this->superAdmin);
        $a = $this->withToken($token)->postJson('/api/v1/plataforma/condominios', datosCondominio())->assertCreated()->json('data.id');
        $b = $this->withToken($token)->postJson('/api/v1/plataforma/condominios', datosCondominio([
            'ruc' => '1791234567001',
            'administrador' => ['cedula' => '1700000001', 'email' => 'otro@correo.ec'],
            'amenidades' => [],
        ]))->assertCreated()->json('data.id');

        $ca = Condominio::find($a);
        $cb = Condominio::find($b);

        expect(enCondominio($ca, fn () => CondominioAmenidad::count()))->toBe(2)
            ->and(enCondominio($cb, fn () => CondominioAmenidad::count()))->toBe(0)
            ->and(enCondominio($cb, fn () => ConfiguracionCobro::count()))->toBe(1)
            ->and(ConfiguracionCobro::count())->toBe(0); // sin condominio activo no hay filas
    });
});

describe('consultas del panel', function () {
    it('lista y busca condominios con su administrador', function () {
        $token = tokenDe($this->superAdmin);
        $this->withToken($token)->postJson('/api/v1/plataforma/condominios', datosCondominio())->assertCreated();
        Condominio::factory()->create(['nombre' => 'Torres del Mirador']);

        $this->withToken($token)->getJson('/api/v1/plataforma/condominios?buscar=arupos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.administradores.0.email', 'maria.rivas@losarupos.ec')
            ->assertJsonPath('meta.pagination.total', 1);

        $this->withToken($token)->getJson('/api/v1/plataforma/condominios?buscar=1792456')
            ->assertJsonCount(1, 'data');
    });

    it('muestra el detalle de un condominio', function () {
        $id = $this->withToken(tokenDe($this->superAdmin))
            ->postJson('/api/v1/plataforma/condominios', datosCondominio())->json('data.id');

        $this->withToken(tokenDe($this->superAdmin))->getJson("/api/v1/plataforma/condominios/{$id}")
            ->assertOk()
            ->assertJsonPath('data.ruc', '1792456781001')
            ->assertJsonPath('data.ubicacion.provincia', 'Pichincha');
    });

    it('entrega planes y amenidades activas', function () {
        $token = tokenDe($this->superAdmin);

        $this->withToken($token)->getJson('/api/v1/plataforma/planes')
            ->assertOk()
            ->assertJsonPath('data.1.codigo', 'profesional')
            ->assertJsonPath('data.1.limite_administrativos', 3);

        $this->withToken($token)->getJson('/api/v1/plataforma/amenidades')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Guardianía 24 h', 'esencial' => true]);
    });

    it('busca si el administrador ya tiene cuenta', function () {
        $condominio = Condominio::factory()->create();
        User::factory()->miembroDe($condominio, Rol::Administrador)->create(['email' => 'maria@correo.ec', 'name' => 'María']);
        $token = tokenDe($this->superAdmin);

        $this->withToken($token)->getJson('/api/v1/plataforma/usuarios/buscar?email=MARIA@correo.ec')
            ->assertOk()->assertJsonPath('data.nombre', 'María')->assertJsonPath('data.condominios', 1);

        $this->withToken($token)->getJson('/api/v1/plataforma/usuarios/buscar?email=nadie@correo.ec')
            ->assertOk()->assertJsonPath('data', null);
    });

    it('entrega la división territorial a cualquier usuario con sesión', function () {
        $condominio = Condominio::factory()->create();
        $residente = User::factory()->miembroDe($condominio, Rol::Residente)->create();

        $respuesta = $this->withToken(tokenDe($residente))->getJson('/api/v1/ubicaciones')->assertOk();

        $provincias = collect($respuesta->json('data'));
        $pichincha = $provincias->firstWhere('codigo', '17');
        $quito = collect($pichincha['cantones'])->firstWhere('codigo', '1701');

        expect($provincias)->toHaveCount(24)
            ->and($pichincha['nombre'])->toBe('Pichincha')
            ->and(collect($quito['parroquias'])->pluck('nombre'))->toContain('Conocoto', 'Cumbayá');
    });
});
