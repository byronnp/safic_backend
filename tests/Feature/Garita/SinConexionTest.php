<?php

use App\Core\Permissions\Rol;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [$this->userA, , $this->unidadA] = residenteDe($this->condominio, 'A-102');
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    [, $tokenA] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->guardia = ($this->como)($tokenG);
    $this->admin = ($this->como)($tokenA);
    $this->tesorero = ($this->como)($tokenT);

    $this->hoy = hoyLocal()->toDateString();
    $this->autorizacion = fn (array $extra = []) => enCondominio($this->condominio, fn () => AutorizacionVisita::create($extra + [
        'unidad_id' => $this->unidadA->id, 'creada_por' => $this->userA->id, 'token' => $this->condominio->id.'-'.bin2hex(random_bytes(8)),
        'visitante_nombre' => 'Juan Pérez', 'visitante_documento' => '1712345678', 'placa' => 'PDQ-3321', 'tipo' => 'unica',
        'fecha_desde' => $this->hoy, 'fecha_hasta' => $this->hoy, 'hora_desde' => '00:00', 'hora_hasta' => '23:59',
    ]));
    $this->ingreso = fn (array $extra = []) => $extra + [
        'uuid' => (string) Str::uuid(), 'tipo' => 'visita', 'nombre' => 'Juan Pérez', 'autorizado_por' => 'qr', 'entrada_en' => now()->subMinutes(20)->toIso8601String(),
    ];
    $this->sincronizar = fn (array $ingresos, array $salidas = []) => ($this->guardia)()->postJson('/api/v1/garita/accesos/sincronizar', ['ingresos' => $ingresos, 'salidas' => $salidas]);
});

it('entrega la copia de los QR que sirven hoy y mañana, sin cédulas', function () {
    $hoyOk = ($this->autorizacion)();
    ($this->autorizacion)(['revocada_en' => now()]);
    ($this->autorizacion)(['usos' => 1]);
    ($this->autorizacion)(['fecha_desde' => hoyLocal()->subDays(5)->toDateString(), 'fecha_hasta' => hoyLocal()->subDay()->toDateString()]);
    $manana = ($this->autorizacion)(['fecha_desde' => hoyLocal()->addDay()->toDateString(), 'fecha_hasta' => hoyLocal()->addDay()->toDateString()]);
    ($this->autorizacion)(['fecha_desde' => hoyLocal()->addDays(3)->toDateString(), 'fecha_hasta' => hoyLocal()->addDays(3)->toDateString()]);

    $r = ($this->guardia)()->getJson('/api/v1/garita/qr/vigentes')->assertOk();

    expect(collect($r->json('data.qrs'))->pluck('token')->all())->toBe([$hoyOk->token, $manana->token])
        ->and($r->json('data.zona'))->toBe('America/Guayaquil')
        ->and($r->json('data.qrs.0'))->toMatchArray(['visitante' => 'Juan Pérez', 'placa' => 'PDQ-3321', 'unidad' => 'A-102', 'autorizo' => $this->userA->name, 'ya_dentro' => false])
        ->and(json_encode($r->json()))->not->toContain('1712345678');
});

it('sincroniza un ingreso hecho sin conexión: se guarda con su hora y cuenta el uso del QR', function () {
    $a = ($this->autorizacion)();
    $entrada = now()->subMinutes(30)->toIso8601String();

    $r = ($this->sincronizar)([($this->ingreso)(['token' => $a->token, 'entrada_en' => $entrada])])->assertOk();

    expect($r->json('data.ingresos.0.resultado'))->toBe('ok');
    $acceso = enCondominio($this->condominio, fn () => AccesoGarita::first());
    expect($acceso->sin_conexion)->toBeTrue()->and($acceso->sincronizado_en)->not->toBeNull()->and($acceso->autorizado_por)->toBe('qr')
        ->and($acceso->unidad_id)->toBe($this->unidadA->id)->and($acceso->nota)->toBeNull()
        ->and($acceso->entrada_en->diffInMinutes(now()))->toBeGreaterThanOrEqual(29)
        ->and(enCondominio($this->condominio, fn () => AutorizacionVisita::find($a->id)->usos))->toBe(1);
});

it('es idempotente: reenviar el mismo ingreso no lo duplica', function () {
    $a = ($this->autorizacion)();
    $i = ($this->ingreso)(['token' => $a->token]);

    ($this->sincronizar)([$i])->assertOk()->assertJsonPath('data.ingresos.0.resultado', 'ok');
    ($this->sincronizar)([$i])->assertOk()->assertJsonPath('data.ingresos.0.resultado', 'duplicado');

    expect(enCondominio($this->condominio, fn () => AccesoGarita::count()))->toBe(1)
        ->and(enCondominio($this->condominio, fn () => AutorizacionVisita::find($a->id)->usos))->toBe(1);
});

it('si el QR ya se usó mientras tanto, el ingreso se guarda igual con una nota (conflicto)', function () {
    $a = ($this->autorizacion)();
    // Otro guardia, con red, ya lo había usado
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['token' => $a->token])->assertCreated();

    $r = ($this->sincronizar)([($this->ingreso)(['token' => $a->token])])->assertOk();

    expect($r->json('data.ingresos.0'))->toMatchArray(['resultado' => 'conflicto', 'motivo' => 'QR_YA_USADO']);
    $acceso = enCondominio($this->condominio, fn () => AccesoGarita::where('sin_conexion', true)->first());
    expect($acceso)->not->toBeNull()->and($acceso->nota)->toContain('Conflicto')
        ->and(enCondominio($this->condominio, fn () => AutorizacionVisita::find($a->id)->usos))->toBe(1);   // el uso no se cuenta dos veces
});

it('un QR revocado mientras no había red también queda con nota', function () {
    $a = ($this->autorizacion)(['revocada_en' => now()->subMinutes(5)]);

    $r = ($this->sincronizar)([($this->ingreso)(['token' => $a->token])])->assertOk();

    expect($r->json('data.ingresos.0.motivo'))->toBe('QR_REVOCADO')
        ->and(enCondominio($this->condominio, fn () => AccesoGarita::first()->nota))->toContain('revocó');
});

it('un QR desconocido no se pierde: se guarda con una nota', function () {
    $r = ($this->sincronizar)([($this->ingreso)(['token' => $this->condominio->id.'-inventado', 'nombre' => 'Sin registro'])])->assertOk();

    expect($r->json('data.ingresos.0.resultado'))->toBe('conflicto')
        ->and(enCondominio($this->condominio, fn () => AccesoGarita::first())->only(['nombre', 'nota']))->toBe(['nombre' => 'Sin registro', 'nota' => 'QR no reconocido al sincronizar']);
});

it('sincroniza ingresos sin QR (llamada, proveedor) y la salida registrada sin conexión', function () {
    $r = ($this->sincronizar)([
        ($this->ingreso)(['tipo' => 'proveedor', 'nombre' => 'Técnicos Andinos', 'destino' => 'Torre B', 'autorizado_por' => 'llamada', 'entrada_en' => now()->subHour()->toIso8601String(), 'salida_en' => now()->subMinutes(10)->toIso8601String()]),
        ($this->ingreso)(['nombre' => 'Carlos Andrade', 'unidad_codigo' => 'a-102', 'autorizado_por' => 'llamada', 'placa' => 'abc-1234']),
    ])->assertOk();

    expect(collect($r->json('data.ingresos'))->pluck('resultado')->all())->toBe(['ok', 'ok']);
    $filas = enCondominio($this->condominio, fn () => AccesoGarita::orderBy('id')->get());
    expect($filas[0]->estado)->toBe('salio')->and($filas[0]->salida_en)->not->toBeNull()->and($filas[0]->destino)->toBe('Torre B')
        ->and($filas[1]->estado)->toBe('dentro')->and($filas[1]->unidad_id)->toBe($this->unidadA->id)->and($filas[1]->placa)->toBe('ABC-1234');
});

it('registra la salida de alguien que entró con red, hecha sin conexión', function () {
    $a = ($this->autorizacion)();
    $id = ($this->guardia)()->postJson('/api/v1/garita/accesos', ['token' => $a->token])->json('data.id');
    $salida = now()->addMinute()->toIso8601String();

    ($this->sincronizar)([], [['acceso_id' => $id, 'salida_en' => $salida]])->assertOk()->assertJsonPath('data.salidas.0.resultado', 'ok');
    ($this->sincronizar)([], [['acceso_id' => $id, 'salida_en' => $salida]])->assertOk()->assertJsonPath('data.salidas.0.resultado', 'duplicado');
    ($this->sincronizar)([], [['acceso_id' => 999999, 'salida_en' => $salida]])->assertOk()->assertJsonPath('data.salidas.0.motivo', 'ACCESO_NO_ENCONTRADO');

    expect(enCondominio($this->condominio, fn () => AccesoGarita::find($id)->estado))->toBe('salio');
});

it('rechaza fechas imposibles: del futuro o de hace más de una semana', function () {
    $r = ($this->sincronizar)([
        ($this->ingreso)(['entrada_en' => now()->addHour()->toIso8601String(), 'autorizado_por' => 'llamada', 'unidad_codigo' => 'A-102']),
        ($this->ingreso)(['entrada_en' => now()->subDays(8)->toIso8601String(), 'autorizado_por' => 'llamada', 'unidad_codigo' => 'A-102']),
    ])->assertOk();

    expect(collect($r->json('data.ingresos'))->pluck('motivo')->all())->toBe(['FECHA_INVALIDA', 'FECHA_INVALIDA'])
        ->and(enCondominio($this->condominio, fn () => AccesoGarita::count()))->toBe(0);
});

it('valida el formato del envío y limita su tamaño', function () {
    ($this->sincronizar)([['uuid' => 'no-es-uuid', 'tipo' => 'visita', 'nombre' => 'X', 'autorizado_por' => 'qr', 'entrada_en' => now()->toIso8601String()]])->assertStatus(422);
    ($this->sincronizar)(array_map(fn () => ($this->ingreso)(['autorizado_por' => 'llamada', 'unidad_codigo' => 'A-102']), range(1, 201)))->assertStatus(422);
    ($this->guardia)()->postJson('/api/v1/garita/accesos/sincronizar', [])->assertStatus(422);
});

it('la bitácora cuenta los accesos sin conexión y dice cuándo fue la última sincronización', function () {
    expect(($this->admin)()->getJson('/api/v1/garita/bitacora')->json('data'))->toMatchArray(['ultima_sincronizacion' => null]);

    ($this->sincronizar)([($this->ingreso)(['autorizado_por' => 'llamada', 'unidad_codigo' => 'A-102'])])->assertOk();

    $b = ($this->admin)()->getJson('/api/v1/garita/bitacora')->json('data');
    expect($b['indicadores']['sin_conexion'])->toBe(1)->and($b['ultima_sincronizacion'])->not->toBeNull()
        ->and($b['accesos'][0])->toMatchArray(['sin_conexion' => true, 'nota' => null]);
});

it('solo el guardia sincroniza o baja la copia', function () {
    ($this->tesorero)()->getJson('/api/v1/garita/qr/vigentes')->assertForbidden();
    ($this->tesorero)()->postJson('/api/v1/garita/accesos/sincronizar', ['ingresos' => []])->assertForbidden();
    ($this->admin)()->getJson('/api/v1/garita/qr/vigentes')->assertOk();
});
