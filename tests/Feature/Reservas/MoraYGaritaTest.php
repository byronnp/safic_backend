<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\DanosEnAreaMail;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create();
    [, $token, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenA] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($token);
    $this->admin = ($this->como)($tokenA);
    $this->guardia = ($this->como)($tokenG);

    $this->area = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Salón comunal', 'cantidad' => 1, 'reservable' => true, 'requiere_aprobacion' => true]));
    enCondominio($this->condominio, fn () => ReglaArea::create([
        'amenidad_id' => $this->area->id, 'abre' => '08:00', 'cierra' => '22:00', 'duracion_min_horas' => 2, 'duracion_max_horas' => 4, 'anticipacion_min_horas' => 24,
        'anticipacion_max_dias' => 60, 'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'costo' => '50.00', 'garantia' => '100.00',
    ]));
    $this->fecha = hoyLocal()->addDays(5)->toDateString();
    $this->reservar = fn () => ($this->residente)()->postJson('/api/v1/mis-reservas', [
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => $this->fecha, 'inicio' => '10:00', 'horas' => 2, 'acepta_reglamento' => true,
    ]);
    $this->restringir = fn () => enCondominio($this->condominio, fn () => RestriccionMora::create([
        'unidad_id' => $this->unidad->id, 'estado' => RestriccionMora::ACTIVA, 'notificada_en' => now()->subDays(10), 'restringe_desde' => hoyLocal()->subDays(5)->toDateString(),
        'deuda_al_notificar' => '240.00', 'meses_al_notificar' => 3,
    ]));
    // Una reserva de hoy, ya confirmada (se crea directo: la anticipación mínima no deja reservar para hoy)
    $this->deHoy = fn (array $extra = []) => enCondominio($this->condominio, fn () => Reserva::create($extra + [
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => hoyLocal()->toDateString(), 'inicio' => '10:00', 'fin' => '12:00',
        'estado' => 'confirmada', 'costo' => '0.00', 'garantia' => '0.00', 'reglamento_aceptado_en' => now(),
    ]));
});

it('una unidad restringida por mora no reserva un área restringible, pero sí una esencial', function () {
    ($this->restringir)();

    ($this->reservar)()->assertStatus(409)->assertJsonPath('error.code', 'UNIDAD_RESTRINGIDA');

    enCondominio($this->condominio, fn () => $this->area->forceFill(['esencial' => true])->save());
    ($this->reservar)()->assertCreated();
});

it('el residente ve en la lista de áreas si su unidad está restringida', function () {
    expect(($this->residente)()->getJson('/api/v1/mis-reservas/areas')->json('data.unidades.0.restringida'))->toBeFalse();

    ($this->restringir)();

    $r = ($this->residente)()->getJson('/api/v1/mis-reservas/areas');
    expect($r->json('data.unidades.0.restringida'))->toBeTrue()->and($r->json('data.areas.0.restringible_por_mora'))->toBeTrue();
});

it('no se aprueba la solicitud de una unidad que quedó restringida, y la lista dice si está al día', function () {
    $mia = ($this->reservar)()->assertCreated();
    $s = ($this->admin)()->getJson('/api/v1/areas-comunes/solicitudes')->json('data.0');
    expect($s)->toMatchArray(['unidad_al_dia' => true, 'unidad_restringida' => false]);

    // La unidad cae en mora: cuota vencida hace 40 días
    enCondominio($this->condominio, fn () => Cuota::create(['unidad_id' => $this->unidad->id, 'periodo' => hoyLocal()->subMonths(2)->startOfMonth()->toDateString(), 'concepto' => 'ordinaria', 'monto' => '80.00', 'pagado' => '0.00', 'vence_el' => hoyLocal()->subDays(40)->toDateString()]));
    expect(($this->admin)()->getJson('/api/v1/areas-comunes/solicitudes')->json('data.0.unidad_al_dia'))->toBeFalse();

    ($this->restringir)();
    ($this->admin)()->postJson('/api/v1/areas-comunes/reservas/'.$mia->json('data.id').'/aprobar')->assertStatus(409)->assertJsonPath('error.code', 'UNIDAD_RESTRINGIDA');
    expect(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('pendiente');
});

it('el guardia ve las reservas de hoy pagadas o gratuitas, no las que deben', function () {
    ($this->deHoy)();                                   // gratis
    $cuota = enCondominio($this->condominio, fn () => Cuota::create(['unidad_id' => $this->unidad->id, 'periodo' => hoyLocal()->startOfMonth()->toDateString(), 'concepto' => 'extraordinaria', 'detalle' => 'Uso', 'monto' => '50.00', 'pagado' => '0.00', 'vence_el' => hoyLocal()->toDateString()]));
    ($this->deHoy)(['inicio' => '14:00', 'fin' => '16:00', 'costo' => '50.00', 'cuota_uso_id' => $cuota->id]);   // debe
    ($this->deHoy)(['inicio' => '18:00', 'fin' => '20:00', 'estado' => 'cancelada']);

    $r = ($this->guardia)()->getJson('/api/v1/garita/reservas-hoy')->assertOk();
    expect($r->json('data.reservas'))->toHaveCount(1)->and($r->json('data.reservas.0'))->toMatchArray(['area' => 'Salón comunal', 'unidad' => 'A-102', 'inicio' => '10:00', 'estado' => 'confirmada', 'restringida' => false]);

    enCondominio($this->condominio, fn () => Cuota::query()->update(['pagado' => '50.00']));
    expect(($this->guardia)()->getJson('/api/v1/garita/reservas-hoy')->json('data.reservas'))->toHaveCount(2);
});

it('el ingreso pasa la reserva a en uso y la entrega a finalizada', function () {
    $r = ($this->deHoy)();

    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk()->assertJsonPath('data.estado', 'en_uso');
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_CONFIRMADA');
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertOk()->assertJsonPath('data.estado', 'finalizada');
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_EN_USO');

    Mail::assertNothingQueued();
    expect(enCondominio($this->condominio, fn () => Reserva::first()->entrega_en))->not->toBeNull();
});

it('si el guardia reporta daños, se guardan y se avisa a la administración', function () {
    $r = ($this->deHoy)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();

    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", ['danos' => 'Silla plegable rota y mancha en el mesón.'])->assertOk();

    expect(enCondominio($this->condominio, fn () => Reserva::first()->danos))->toBe('Silla plegable rota y mancha en el mesón.');
    Mail::assertQueued(DanosEnAreaMail::class, fn ($m) => $m->area === 'Salón comunal' && $m->unidad === 'A-102');
});

it('no da el ingreso si debe, si no es de hoy o si la unidad está restringida', function () {
    $cuota = enCondominio($this->condominio, fn () => Cuota::create(['unidad_id' => $this->unidad->id, 'periodo' => hoyLocal()->startOfMonth()->toDateString(), 'concepto' => 'extraordinaria', 'detalle' => 'Uso', 'monto' => '50.00', 'pagado' => '0.00', 'vence_el' => hoyLocal()->toDateString()]));
    $debe = ($this->deHoy)(['costo' => '50.00', 'cuota_uso_id' => $cuota->id]);
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$debe->id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_SIN_PAGAR');

    $manana = ($this->deHoy)(['fecha' => hoyLocal()->addDay()->toDateString(), 'inicio' => '14:00', 'fin' => '16:00']);
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$manana->id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_ES_DE_HOY');

    $libre = ($this->deHoy)(['inicio' => '18:00', 'fin' => '20:00']);
    ($this->restringir)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$libre->id}/ingreso")->assertStatus(409)->assertJsonPath('error.code', 'UNIDAD_RESTRINGIDA');
    expect(($this->guardia)()->getJson('/api/v1/garita/reservas-hoy')->json('data.reservas.0.restringida'))->toBeTrue();
});

it('solo el guardia y la administración usan la garita de reservas', function () {
    $r = ($this->deHoy)();

    ($this->residente)()->getJson('/api/v1/garita/reservas-hoy')->assertForbidden();
    ($this->residente)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertForbidden();
    ($this->admin)()->getJson('/api/v1/garita/reservas-hoy')->assertOk();
});

it('una reserva en uso sigue ocupando el horario', function () {
    $r = ($this->deHoy)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();

    expect(fn () => ($this->deHoy)(['estado' => 'confirmada']))->toThrow(QueryException::class);
});

it('el guardia adjunta fotos al reporte de daños y la administración las ve con enlace temporal', function () {
    Storage::fake('s3');
    $r = ($this->deHoy)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();

    ($this->guardia)()->post("/api/v1/garita/reservas/{$r->id}/entrega", [
        'danos' => 'Silla plegable rota.',
        'fotos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
    ], ['Accept' => 'application/json'])->assertOk();

    $d = ($this->admin)()->getJson("/api/v1/areas-comunes/reservas/{$r->id}/danos")->assertOk()->json('data');
    expect($d['danos'])->toBe('Silla plegable rota.')->and($d['fotos'])->toHaveCount(2)->and($d['unidad'])->toBe('A-102')
        ->and($d['area'])->toBe('Salón comunal');
    Mail::assertQueued(DanosEnAreaMail::class, fn ($m) => $m->fotos === 2);
});

it('no se aceptan fotos sin descripción del daño, ni más de 5, ni archivos que no son imagen', function () {
    Storage::fake('s3');
    $r = ($this->deHoy)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();
    $foto = fn () => UploadedFile::fake()->image('x.jpg');
    $enviar = fn (array $datos) => ($this->guardia)()->post("/api/v1/garita/reservas/{$r->id}/entrega", $datos, ['Accept' => 'application/json']);

    $enviar(['fotos' => [$foto()]])->assertStatus(422)->assertJsonPath('error.code', 'FOTOS_SIN_DANOS');
    $enviar(['danos' => 'Roto', 'fotos' => array_map($foto, range(1, 6))])->assertStatus(422);
    $enviar(['danos' => 'Roto', 'fotos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertStatus(422);
    // Nada de lo rechazado dejó la reserva finalizada
    expect(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe(Reserva::EN_USO);
});

it('una reserva recibida sin daños no tiene reporte que ver', function () {
    $r = ($this->deHoy)();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertOk();

    ($this->admin)()->getJson("/api/v1/areas-comunes/reservas/{$r->id}/danos")->assertStatus(404)->assertJsonPath('error.code', 'RESERVA_SIN_DANOS');
});

/** Una reserva de hoy con la garantía cobrada (pagada o no), lista para recibirse. */
function reservaConGarantia(object $t, string $garantia = '50.00', bool $pagada = true, array $extra = []): Reserva
{
    $r = ($t->deHoy)(['garantia' => $garantia] + $extra);
    $cuota = enCondominio($t->condominio, fn () => Cuota::create([
        'unidad_id' => $t->unidad->id, 'periodo' => hoyLocal()->startOfMonth()->toDateString(), 'concepto' => 'extraordinaria',
        'detalle' => "Garantía Salón comunal · reserva {$r->id}", 'monto' => $garantia, 'pagado' => $pagada ? $garantia : '0.00', 'vence_el' => hoyLocal()->toDateString(),
    ]));
    enCondominio($t->condominio, fn () => $r->forceFill(['cuota_garantia_id' => $cuota->id])->save());
    // Una garantía sin pagar no deja dar el ingreso: se paga para entrar y se vuelve a dejar como estaba
    if (! $pagada) {
        enCondominio($t->condominio, fn () => $cuota->forceFill(['pagado' => $garantia])->save());
    }
    ($t->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/ingreso")->assertOk();
    if (! $pagada) {
        enCondominio($t->condominio, fn () => $cuota->forceFill(['pagado' => '0.00'])->save());
    }

    return $r;
}

/** Una reserva ya recibida con daños reportados (garantía pagada). */
function reservaConDanos(object $t, string $garantia = '50.00'): Reserva
{
    $r = reservaConGarantia($t, $garantia);
    ($t->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", ['danos' => 'Silla plegable rota.'])->assertOk();

    return $r;
}

it('sin cargo: no se cobra nada y la garantía queda completa por devolver', function () {
    $r = reservaConDanos($this);

    $d = ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'sin_cargo', 'nota' => 'Era desgaste normal.'])
        ->assertOk()->json('data.resolucion');

    expect($d)->toMatchArray(['decision' => 'sin_cargo', 'monto' => null, 'por_devolver' => '50.00', 'cobrada_en_cuota' => false, 'nota' => 'Era desgaste normal.']);
    expect(enCondominio($this->condominio, fn () => Cuota::where('detalle', 'like', 'Daños%')->count()))->toBe(0);
});

it('descontar de la garantía: el daño se queda de la garantía y el resto queda por devolver, sin crear cuota', function () {
    $r = reservaConDanos($this);

    $d = ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'descontar_garantia', 'monto' => '20.50'])
        ->assertOk()->json('data.resolucion');

    expect($d)->toMatchArray(['decision' => 'descontar_garantia', 'monto' => '20.50', 'por_devolver' => '29.50', 'cobrada_en_cuota' => false]);
    expect(enCondominio($this->condominio, fn () => Cuota::where('detalle', 'like', 'Daños%')->count()))->toBe(0);
});

it('no se descuenta más que la garantía', function () {
    $r = reservaConDanos($this);

    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'descontar_garantia', 'monto' => '50.01'])
        ->assertStatus(422)->assertJsonPath('error.code', 'MONTO_SUPERA_GARANTIA');
    expect(enCondominio($this->condominio, fn () => Reserva::find($r->id)->danos_decision))->toBeNull();
});

it('cobrar a la cuenta crea la cuota extraordinaria de la unidad y la garantía sigue completa por devolver', function () {
    $r = reservaConDanos($this);

    $d = ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'cobrar_cuenta', 'monto' => '80'])
        ->assertOk()->assertJsonPath('message', 'Daño cobrado en la cuenta de la unidad.')->json('data.resolucion');

    expect($d)->toMatchArray(['decision' => 'cobrar_cuenta', 'monto' => '80.00', 'por_devolver' => '50.00', 'cobrada_en_cuota' => true]);
    $cuota = enCondominio($this->condominio, fn () => Cuota::where('detalle', 'like', 'Daños%')->first());
    expect($cuota->unidad_id)->toBe($this->unidad->id)->and($cuota->concepto)->toBe('extraordinaria')
        ->and((string) $cuota->monto)->toBe('80.00')->and($cuota->detalle)->toBe("Daños Salón comunal · reserva {$r->id}")
        ->and($cuota->vence_el->toDateString())->toBe(hoyLocal()->addDays(10)->toDateString());
});

it('se decide una sola vez', function () {
    $r = reservaConDanos($this);
    $url = "/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver";
    ($this->admin)()->postJson($url, ['decision' => 'cobrar_cuenta', 'monto' => '30'])->assertOk();

    ($this->admin)()->postJson($url, ['decision' => 'cobrar_cuenta', 'monto' => '30'])->assertStatus(409)->assertJsonPath('error.code', 'DANOS_YA_RESUELTOS');
    expect(enCondominio($this->condominio, fn () => Cuota::where('detalle', 'like', 'Daños%')->count()))->toBe(1);
});

it('en un mes cerrado no se cobra y la decisión queda sin tomar', function () {
    $r = reservaConDanos($this);
    enCondominio($this->condominio, fn () => PeriodoFinanciero::create(['periodo' => hoyLocal()->startOfMonth()->toDateString(), 'estado' => 'cerrado', 'emitido_en' => now()]));

    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'cobrar_cuenta', 'monto' => '30'])
        ->assertStatus(409)->assertJsonPath('error.code', 'PERIODO_CERRADO');
    expect(enCondominio($this->condominio, fn () => Reserva::find($r->id)->danos_decision))->toBeNull();
});

it('valida la decisión y el monto, y solo la administración resuelve', function () {
    $r = reservaConDanos($this);
    $url = "/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver";

    ($this->admin)()->postJson($url, ['decision' => 'regalar'])->assertStatus(422);
    ($this->admin)()->postJson($url, ['decision' => 'cobrar_cuenta'])->assertStatus(422);
    ($this->admin)()->postJson($url, ['decision' => 'cobrar_cuenta', 'monto' => '0'])->assertStatus(422);
    ($this->admin)()->postJson($url, ['decision' => 'cobrar_cuenta', 'monto' => '12.345'])->assertStatus(422);
    ($this->guardia)()->postJson($url, ['decision' => 'sin_cargo'])->assertForbidden();
    ($this->residente)()->postJson($url, ['decision' => 'sin_cargo'])->assertForbidden();
});

it('la lista de daños pone primero lo que espera decisión y cuenta los pendientes', function () {
    $a = reservaConDanos($this);
    $b = ($this->deHoy)(['inicio' => '18:00', 'fin' => '20:00', 'garantia' => '0.00']);
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$b->id}/ingreso")->assertOk();
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$b->id}/entrega", ['danos' => 'Mancha en el piso.'])->assertOk();
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$a->id}/danos/resolver", ['decision' => 'sin_cargo'])->assertOk();

    $l = ($this->admin)()->getJson('/api/v1/areas-comunes/danos')->assertOk()->json('data');

    expect($l['pendientes'])->toBe(1)->and(array_column($l['danos'], 'reserva_id'))->toBe([$b->id, $a->id])
        ->and($l['danos'][0])->toMatchArray(['decision' => null, 'por_devolver' => null, 'unidad' => 'A-102', 'area' => 'Salón comunal'])
        ->and($l['danos'][1])->toMatchArray(['decision' => 'sin_cargo', 'por_devolver' => '50.00']);
    ($this->guardia)()->getJson('/api/v1/areas-comunes/danos')->assertForbidden();
});

it('garantía sin daños: se devuelve completa y se registra la devolución una sola vez', function () {
    $r = reservaConGarantia($this, '100.00');
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertOk();

    $l = ($this->admin)()->getJson('/api/v1/areas-comunes/garantias')->assertOk()->json('data');
    expect($l)->toMatchArray(['por_devolver' => 1, 'total_por_devolver' => '100.00'])
        ->and($l['garantias'][0])->toMatchArray(['reserva_id' => $r->id, 'estado' => 'por_devolver', 'monto' => '100.00', 'unidad' => 'A-102', 'devolucion' => null]);

    $url = "/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver";
    ($this->admin)()->postJson($url, ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString()])->assertOk()->assertJsonPath('data.monto', '100.00');

    $l = ($this->admin)()->getJson('/api/v1/areas-comunes/garantias')->json('data');
    expect($l['por_devolver'])->toBe(0)->and($l['total_por_devolver'])->toBe('0.00')
        ->and($l['garantias'][0])->toMatchArray(['estado' => 'devuelta', 'monto' => '100.00', 'devolucion' => ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString(), 'referencia' => null]]);
    ($this->admin)()->postJson($url, ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString()])->assertStatus(409)->assertJsonPath('error.code', 'GARANTIA_YA_DEVUELTA');
});

it('con daños descontados se devuelve el resto; por transferencia pide la referencia', function () {
    $r = reservaConDanos($this);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/danos/resolver", ['decision' => 'descontar_garantia', 'monto' => '20.50'])->assertOk();
    $url = "/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver";

    ($this->admin)()->postJson($url, ['forma' => 'transferencia', 'fecha' => hoyLocal()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'REFERENCIA_REQUERIDA');
    ($this->admin)()->postJson($url, ['forma' => 'transferencia', 'fecha' => hoyLocal()->toDateString(), 'referencia' => ' 884512 '])->assertOk()->assertJsonPath('data.monto', '29.50');

    $reserva = enCondominio($this->condominio, fn () => Reserva::find($r->id));
    expect($reserva->garantia_devuelta_referencia)->toBe('884512')->and($reserva->garantia_devuelta_forma)->toBe('transferencia');
});

it('mientras no se decidan los daños no se sabe cuánto devolver y no se puede registrar', function () {
    $r = reservaConDanos($this);

    $l = ($this->admin)()->getJson('/api/v1/areas-comunes/garantias')->json('data');
    expect($l['garantias'][0]['estado'])->toBe('esperando_decision')->and($l['por_devolver'])->toBe(0);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver", ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString()])
        ->assertStatus(409)->assertJsonPath('error.code', 'DANOS_SIN_DECIDIR');
});

it('si el daño consume toda la garantía, o la garantía no se pagó, no hay nada que devolver', function () {
    $consumida = reservaConDanos($this);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$consumida->id}/danos/resolver", ['decision' => 'descontar_garantia', 'monto' => '50'])->assertOk();
    $sinPagar = reservaConGarantia($this, '40.00', pagada: false, extra: ['inicio' => '18:00', 'fin' => '20:00']);
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$sinPagar->id}/entrega", [])->assertOk();

    expect(($this->admin)()->getJson('/api/v1/areas-comunes/garantias')->json('data.garantias'))->toBe([]);
    foreach ([$consumida, $sinPagar] as $r) {
        ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver", ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString()])
            ->assertStatus(409)->assertJsonPath('error.code', 'GARANTIA_NO_APLICA');
    }
});

it('valida la forma y la fecha de la devolución, y solo la administración la registra', function () {
    $r = reservaConGarantia($this);
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertOk();
    $url = "/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver";
    $hoy = hoyLocal()->toDateString();

    ($this->admin)()->postJson($url, ['fecha' => $hoy])->assertStatus(422);
    ($this->admin)()->postJson($url, ['forma' => 'cheque', 'fecha' => $hoy])->assertStatus(422);
    ($this->admin)()->postJson($url, ['forma' => 'efectivo', 'fecha' => '17/10/2026'])->assertStatus(422);
    ($this->admin)()->postJson($url, ['forma' => 'efectivo', 'fecha' => hoyLocal()->addDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_FUTURA');
    ($this->admin)()->postJson($url, ['forma' => 'efectivo', 'fecha' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_ANTERIOR');
    ($this->guardia)()->postJson($url, ['forma' => 'efectivo', 'fecha' => $hoy])->assertForbidden();
    ($this->residente)()->postJson($url, ['forma' => 'efectivo', 'fecha' => $hoy])->assertForbidden();
    ($this->guardia)()->getJson('/api/v1/areas-comunes/garantias')->assertForbidden();
});

it('el residente ve qué pasó con su garantía: por devolver y luego devuelta', function () {
    $r = reservaConGarantia($this, '100.00');
    ($this->guardia)()->postJson("/api/v1/garita/reservas/{$r->id}/entrega", [])->assertOk();
    $mia = fn () => collect(($this->residente)()->getJson('/api/v1/mis-reservas')->assertOk()->json('data'))->firstWhere('id', $r->id);

    expect($mia())->toMatchArray(['garantia_estado' => 'por_devolver', 'garantia_monto' => '100.00', 'garantia_devuelta_en' => null]);

    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$r->id}/garantia/devolver", ['forma' => 'efectivo', 'fecha' => hoyLocal()->toDateString()])->assertOk();
    expect($mia())->toMatchArray(['garantia_estado' => 'devuelta', 'garantia_monto' => '100.00', 'garantia_devuelta_en' => hoyLocal()->toDateString()]);
});
