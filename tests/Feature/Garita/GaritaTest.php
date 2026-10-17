<?php

use App\Core\Permissions\Rol;
use App\Modules\Garita\Actions\AnonimizarGaritaAction;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Plataforma\Models\Condominio;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [$this->residenteUser, $tokenR, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    [, $tokenA] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($tokenR);
    $this->vecino = ($this->como)($tokenB);
    $this->guardia = ($this->como)($tokenG);
    $this->admin = ($this->como)($tokenA);
    $this->tesorero = ($this->como)($tokenT);

    $this->hoy = hoyLocal()->toDateString();
    $this->autorizacion = fn (array $extra = []) => enCondominio($this->condominio, fn () => AutorizacionVisita::create($extra + [
        'unidad_id' => $this->unidad->id, 'creada_por' => $this->residenteUser->id, 'token' => $this->condominio->id.'-'.bin2hex(random_bytes(8)),
        'visitante_nombre' => 'Juan Pérez', 'visitante_documento' => '1712345678', 'placa' => 'PDQ-3321', 'tipo' => 'unica',
        'fecha_desde' => $this->hoy, 'fecha_hasta' => $this->hoy, 'hora_desde' => '00:00', 'hora_hasta' => '23:59',
    ]));
    $this->entrar = fn (string $token) => ($this->guardia)()->postJson('/api/v1/garita/accesos', ['token' => $token]);
});

it('valida el QR y dice quién es, a dónde va y quién lo autorizó', function () {
    $a = ($this->autorizacion)();

    $r = ($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => $a->token])->assertOk();

    expect($r->json('data'))->toMatchArray(['valido' => true, 'motivo' => null])
        ->and($r->json('data.visita'))->toMatchArray(['visitante' => 'Juan Pérez', 'placa' => 'PDQ-3321', 'unidad' => 'A-102', 'tipo' => 'unica'])
        ->and($r->json('data.visita.autorizo'))->toBe($this->residenteUser->name)
        ->and(json_encode($r->json()))->not->toContain('1712345678');
});

it('explica por qué un QR no sirve: revocado, usado, vencido, otro día, fuera de horario', function () {
    $validar = fn ($a) => ($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => $a->token])->assertOk()->json('data');

    expect($validar(($this->autorizacion)(['revocada_en' => now()]))['motivo'])->toBe('QR_REVOCADO')
        ->and($validar(($this->autorizacion)(['usos' => 1]))['motivo'])->toBe('QR_YA_USADO')
        ->and($validar(($this->autorizacion)(['fecha_desde' => hoyLocal()->subDays(3)->toDateString(), 'fecha_hasta' => hoyLocal()->subDay()->toDateString()]))['motivo'])->toBe('QR_VENCIDO')
        ->and($validar(($this->autorizacion)(['fecha_desde' => hoyLocal()->addDay()->toDateString(), 'fecha_hasta' => hoyLocal()->addDay()->toDateString()]))['motivo'])->toBe('QR_AUN_NO_VIGENTE');

    $otroDia = array_values(array_diff([1, 2, 3, 4, 5, 6, 7], [hoyLocal()->isoWeekday()]));
    expect($validar(($this->autorizacion)(['tipo' => 'recurrente', 'dias_semana' => $otroDia]))['motivo'])->toBe('QR_DIA_NO_HABILITADO');

    // Fuera de horario: la ventana ya terminó (a las 00:00 no hay ventana de 1 minuto atrás; se usa una que nunca coincide)
    $ahora = CarbonImmutable::now('America/Guayaquil');
    $ventana = $ahora->hour >= 1 ? ['00:00', '00:01'] : ['23:58', '23:59'];
    expect($validar(($this->autorizacion)(['hora_desde' => $ventana[0], 'hora_hasta' => $ventana[1]]))['motivo'])->toBe('QR_FUERA_DE_HORARIO');
});

it('un QR inventado o de otro condominio responde 404', function () {
    ($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => 'nada'])->assertNotFound()->assertJsonPath('error.code', 'QR_NO_RECONOCIDO');
    ($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => $this->condominio->id.'-inexistente'])->assertNotFound();

    $otro = Condominio::factory()->create();
    $ajeno = enCondominio($otro, fn () => AutorizacionVisita::create([
        'unidad_id' => residenteDe($otro, 'Z-1')[2]->id, 'token' => $otro->id.'-ajeno', 'visitante_nombre' => 'Ajeno', 'tipo' => 'unica',
        'fecha_desde' => $this->hoy, 'fecha_hasta' => $this->hoy, 'hora_desde' => '00:00', 'hora_hasta' => '23:59',
    ]));
    ($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => $ajeno->token])->assertNotFound();
    ($this->entrar)($ajeno->token)->assertNotFound();
});

it('registrar el ingreso con QR cuenta el uso: uno de un solo ingreso no se repite', function () {
    $a = ($this->autorizacion)();

    ($this->entrar)($a->token)->assertCreated()->assertJsonPath('data.estado', 'dentro');
    expect(enCondominio($this->condominio, fn () => AutorizacionVisita::find($a->id)->usos))->toBe(1);

    $acceso = enCondominio($this->condominio, fn () => AccesoGarita::first());
    expect($acceso->toArray())->toMatchArray(['tipo' => 'visita', 'nombre' => 'Juan Pérez', 'autorizado_por' => 'qr', 'placa' => 'PDQ-3321'])
        ->and($acceso->documento)->toBe('1712345678');

    // Ya está dentro, y aunque salga, el QR de un solo ingreso ya no sirve
    ($this->entrar)($a->token)->assertStatus(409)->assertJsonPath('error.code', 'QR_YA_USADO');
    ($this->guardia)()->postJson("/api/v1/garita/accesos/{$acceso->id}/salida")->assertOk()->assertJsonPath('data.estado', 'salio');
    ($this->entrar)($a->token)->assertStatus(409)->assertJsonPath('error.code', 'QR_YA_USADO');
});

it('una visita recurrente puede volver a entrar después de salir, pero no estando dentro', function () {
    $a = ($this->autorizacion)(['tipo' => 'recurrente', 'dias_semana' => [1, 2, 3, 4, 5, 6, 7]]);

    ($this->entrar)($a->token)->assertCreated();
    ($this->entrar)($a->token)->assertStatus(409)->assertJsonPath('error.code', 'QR_YA_DENTRO');
    expect(($this->guardia)()->postJson('/api/v1/garita/qr/validar', ['token' => $a->token])->json('data.motivo'))->toBe('QR_YA_DENTRO');

    $acceso = enCondominio($this->condominio, fn () => AccesoGarita::first());
    ($this->guardia)()->postJson("/api/v1/garita/accesos/{$acceso->id}/salida")->assertOk();
    ($this->guardia)()->postJson("/api/v1/garita/accesos/{$acceso->id}/salida")->assertStatus(409)->assertJsonPath('error.code', 'YA_SALIO');
    ($this->entrar)($a->token)->assertCreated();
    expect(enCondominio($this->condominio, fn () => AccesoGarita::count()))->toBe(2);
});

it('registra visitas sin QR, proveedores y delivery tras confirmar por llamada', function () {
    $r = fn (array $d) => ($this->guardia)()->postJson('/api/v1/garita/accesos', $d + ['autorizado_por' => 'llamada']);

    $r(['tipo' => 'visita', 'nombre' => 'Carlos Andrade', 'unidad_codigo' => 'a-102', 'placa' => 'abc-1234'])->assertCreated();
    $r(['tipo' => 'proveedor', 'nombre' => 'Técnicos Andinos', 'destino' => 'Torre B'])->assertCreated();
    $r(['tipo' => 'delivery', 'nombre' => 'Rappi · Luis M.', 'unidad_codigo' => 'B-9'])->assertCreated();

    expect(enCondominio($this->condominio, fn () => AccesoGarita::orderBy('id')->pluck('autorizado_por', 'nombre')->all()))->toBe(['Carlos Andrade' => 'llamada', 'Técnicos Andinos' => 'llamada', 'Rappi · Luis M.' => 'llamada']);
    $r(['tipo' => 'visita', 'nombre' => 'Sin unidad'])->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_REQUERIDA');
    $r(['tipo' => 'proveedor', 'nombre' => 'Sin destino'])->assertStatus(422)->assertJsonPath('error.code', 'DESTINO_REQUERIDO');
    $r(['tipo' => 'visita', 'nombre' => 'Unidad falsa', 'unidad_codigo' => 'ZZZ-9'])->assertStatus(422)->assertJsonPath('error.code', 'UNIDAD_NO_ENCONTRADA');
    $r(['tipo' => 'otro', 'nombre' => 'Raro', 'destino' => 'x'])->assertStatus(422);
    $r(['tipo' => 'visita', 'nombre' => 'Ab', 'unidad_codigo' => 'A-102'])->assertStatus(422);
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['tipo' => 'visita', 'nombre' => 'Sin autorizador', 'unidad_codigo' => 'A-102'])->assertStatus(422);
});

it('el panel muestra quién está dentro y las visitas esperadas hoy', function () {
    $dentro = ($this->autorizacion)(['visitante_nombre' => 'Ya entró']);
    ($this->autorizacion)(['visitante_nombre' => 'Esperada']);
    ($this->autorizacion)(['visitante_nombre' => 'Revocada', 'revocada_en' => now()]);
    ($this->autorizacion)(['visitante_nombre' => 'Mañana', 'fecha_desde' => hoyLocal()->addDay()->toDateString(), 'fecha_hasta' => hoyLocal()->addDay()->toDateString()]);
    ($this->entrar)($dentro->token)->assertCreated();
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['tipo' => 'proveedor', 'nombre' => 'Plomero', 'destino' => 'B-306', 'autorizado_por' => 'administracion'])->assertCreated();

    $r = ($this->guardia)()->getJson('/api/v1/garita/panel')->assertOk();

    expect(collect($r->json('data.dentro'))->pluck('nombre')->all())->toBe(['Ya entró', 'Plomero'])
        ->and(collect($r->json('data.esperadas'))->pluck('nombre')->all())->toBe(['Esperada']);
});

it('la bitácora de la administración trae los indicadores del día y filtra', function () {
    $a = ($this->autorizacion)();
    ($this->entrar)($a->token)->assertCreated();
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['tipo' => 'visita', 'nombre' => 'Carlos Andrade', 'unidad_codigo' => 'A-102', 'autorizado_por' => 'llamada'])->assertCreated();
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['tipo' => 'proveedor', 'nombre' => 'Técnicos', 'destino' => 'Torre B', 'autorizado_por' => 'llamada'])->assertCreated();
    enCondominio($this->condominio, fn () => AccesoGarita::create(['tipo' => 'visita', 'nombre' => 'De ayer', 'autorizado_por' => 'qr', 'estado' => 'salio', 'entrada_en' => now()->subDay(), 'salida_en' => now()->subDay()->addHour()]));

    $r = ($this->admin)()->getJson('/api/v1/garita/bitacora')->assertOk();

    expect($r->json('data.indicadores'))->toBe(['dentro_ahora' => 3, 'proveedores_dentro' => 1, 'ingresos_hoy' => 3, 'con_qr_hoy' => 1, 'sin_anunciar_hoy' => 1, 'sin_conexion' => 0])
        ->and($r->json('data.accesos'))->toHaveCount(3)
        ->and($r->json('data.accesos.0'))->toHaveKeys(['nombre', 'tipo', 'unidad', 'autorizado_por', 'estado', 'entrada_en', 'registrado_por']);

    $soloProveedores = ($this->admin)()->getJson('/api/v1/garita/bitacora?tipo=proveedor')->json('data.accesos');
    expect(collect($soloProveedores)->pluck('nombre')->all())->toBe(['Técnicos']);
    $busqueda = ($this->admin)()->getJson('/api/v1/garita/bitacora?q=carlos')->json('data.accesos');
    expect(collect($busqueda)->pluck('nombre')->all())->toBe(['Carlos Andrade']);
    $ayer = hoyLocal()->subDay()->toDateString();
    expect(collect(($this->admin)()->getJson("/api/v1/garita/bitacora?desde={$ayer}&hasta={$ayer}")->json('data.accesos'))->pluck('nombre')->all())->toBe(['De ayer']);

    ($this->admin)()->getJson('/api/v1/garita/bitacora?desde=2026-01-01&hasta=2026-06-30')->assertStatus(422)->assertJsonPath('error.code', 'RANGO_MUY_LARGO');
});

it('el residente ve los ingresos a su unidad, no los de otras', function () {
    $a = ($this->autorizacion)();
    ($this->entrar)($a->token)->assertCreated();
    ($this->guardia)()->postJson('/api/v1/garita/accesos', ['tipo' => 'visita', 'nombre' => 'Del vecino', 'unidad_codigo' => 'B-9', 'autorizado_por' => 'llamada'])->assertCreated();

    $ingresos = ($this->residente)()->getJson('/api/v1/mis-visitas')->json('data.ingresos');

    expect(collect($ingresos)->pluck('nombre')->all())->toBe(['Juan Pérez'])
        ->and($ingresos[0])->toMatchArray(['tipo' => 'visita', 'autorizado_por' => 'qr', 'estado' => 'dentro']);
});

it('a los 12 meses se anonimizan los datos del visitante y queda el hecho', function () {
    $viejo = enCondominio($this->condominio, fn () => AccesoGarita::create(['unidad_id' => $this->unidad->id, 'tipo' => 'visita', 'nombre' => 'Persona Antigua', 'documento' => '1799999999', 'placa' => 'AAA-111', 'autorizado_por' => 'llamada', 'estado' => 'salio', 'entrada_en' => now()->subMonths(13), 'salida_en' => now()->subMonths(13)->addHour()]));
    $reciente = enCondominio($this->condominio, fn () => AccesoGarita::create(['tipo' => 'visita', 'nombre' => 'Persona Reciente', 'autorizado_por' => 'llamada', 'estado' => 'dentro', 'entrada_en' => now()->subMonths(2)]));
    ($this->autorizacion)(['visitante_nombre' => 'Autorización Vieja', 'fecha_desde' => hoyLocal()->subMonths(14)->toDateString(), 'fecha_hasta' => hoyLocal()->subMonths(14)->toDateString()]);

    $r = enCondominio($this->condominio, fn () => app(AnonimizarGaritaAction::class)->execute());

    expect($r)->toBe(['accesos' => 1, 'autorizaciones' => 1, 'paquetes' => 0]);
    $v = enCondominio($this->condominio, fn () => AccesoGarita::find($viejo->id));
    expect($v->nombre)->toBe('Visitante anonimizado')->and($v->documento)->toBeNull()->and($v->placa)->toBeNull()->and($v->unidad_id)->toBe($this->unidad->id)
        ->and(enCondominio($this->condominio, fn () => AccesoGarita::find($reciente->id)->nombre))->toBe('Persona Reciente');
    $this->artisan('garita:anonimizar')->assertSuccessful();
});

it('solo el guardia registra y solo la administración ve la bitácora', function () {
    ($this->residente)()->getJson('/api/v1/garita/panel')->assertForbidden();
    ($this->tesorero)()->postJson('/api/v1/garita/qr/validar', ['token' => 'x'])->assertForbidden();
    ($this->residente)()->postJson('/api/v1/garita/accesos', [])->assertForbidden();
    ($this->guardia)()->getJson('/api/v1/garita/bitacora')->assertForbidden();
    ($this->tesorero)()->getJson('/api/v1/garita/bitacora')->assertForbidden();
    ($this->admin)()->getJson('/api/v1/garita/panel')->assertOk();
});
