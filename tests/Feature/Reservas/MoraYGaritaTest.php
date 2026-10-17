<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\DanosEnAreaMail;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;

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
