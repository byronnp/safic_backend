<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\ReservaActualizadaMail;
use App\Modules\Reservas\Models\BloqueoArea;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
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

    $this->area = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Salón comunal', 'cantidad' => 1, 'reservable' => true]));
    enCondominio($this->condominio, fn () => ReglaArea::create([
        'amenidad_id' => $this->area->id, 'abre' => '08:00', 'cierra' => '22:00', 'duracion_min_horas' => 2, 'duracion_max_horas' => 4, 'anticipacion_min_horas' => 24,
        'anticipacion_max_dias' => 60, 'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'costo' => '50.00', 'garantia' => '100.00',
    ]));
    $this->fecha = hoyLocal()->addDays(5)->toDateString();
    $this->reservar = fn (string $inicio = '10:00') => ($this->residente)()->postJson('/api/v1/mis-reservas', [
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => $this->fecha, 'inicio' => $inicio, 'horas' => 2, 'acepta_reglamento' => true,
    ]);
    $this->bloquear = fn (array $extra = []) => ($this->admin)()->postJson('/api/v1/areas-comunes/bloqueos', $extra + [
        'amenidad_id' => $this->area->id, 'fecha' => $this->fecha, 'inicio' => '10:00', 'fin' => '12:00', 'motivo' => 'Mantenimiento de la parrilla',
    ]);
});

it('la agenda trae las áreas, las reservas vivas y los bloqueos del rango', function () {
    ($this->reservar)()->assertCreated();
    ($this->bloquear)(['inicio' => '14:00', 'fin' => '16:00'])->assertCreated();
    ($this->reservar)('14:00')->assertStatus(409)->assertJsonPath('error.code', 'HORARIO_BLOQUEADO');

    $r = ($this->admin)()->getJson("/api/v1/areas-comunes/agenda?desde={$this->fecha}&dias=1")->assertOk();

    expect($r->json('data.areas.0'))->toMatchArray(['nombre' => 'Salón comunal', 'costo' => '50.00', 'garantia' => '100.00', 'en_mantenimiento' => false])
        ->and($r->json('data.reservas'))->toHaveCount(1)
        ->and($r->json('data.reservas.0'))->toMatchArray(['unidad' => 'A-102', 'inicio' => '10:00', 'fin' => '12:00', 'estado' => 'confirmada'])
        ->and($r->json('data.bloqueos.0'))->toMatchArray(['inicio' => '14:00', 'fin' => '16:00', 'motivo' => 'Mantenimiento de la parrilla']);

    // Fuera del rango no aparece
    expect(($this->admin)()->getJson('/api/v1/areas-comunes/agenda?desde='.hoyLocal()->toDateString().'&dias=1')->json('data.reservas'))->toBe([]);
});

it('un bloqueo cierra esas franjas en la disponibilidad del residente y se quita al desbloquear', function () {
    $b = ($this->bloquear)()->assertCreated();
    $franjas = fn () => collect(($this->residente)()->getJson("/api/v1/mis-reservas/areas/{$this->area->id}/disponibilidad?desde={$this->fecha}&dias=1")->json('data.0.franjas'))->pluck('estado', 'inicio');

    expect($franjas()['10:00'])->toBe('cerrada')->and($franjas()['12:00'])->toBe('libre');

    ($this->admin)()->deleteJson('/api/v1/areas-comunes/bloqueos/'.$b->json('data.id'))->assertOk();
    expect($franjas()['10:00'])->toBe('libre');
});

it('bloquear todo el día cierra todas las franjas', function () {
    ($this->bloquear)(['inicio' => null, 'fin' => null])->assertCreated();

    $f = ($this->residente)()->getJson("/api/v1/mis-reservas/areas/{$this->area->id}/disponibilidad?desde={$this->fecha}&dias=1")->json('data.0.franjas');
    expect(collect($f)->pluck('estado')->unique()->all())->toBe(['cerrada']);
});

it('si hay reservas encima no bloquea a ciegas: pide confirmar, y al confirmar las cancela y avisa', function () {
    $mia = ($this->reservar)()->assertCreated();

    ($this->bloquear)()->assertStatus(409)->assertJsonPath('error.code', 'HAY_RESERVAS_AFECTADAS')->assertJsonPath('error.details.reservas', 1);
    expect(enCondominio($this->condominio, fn () => BloqueoArea::count()))->toBe(0)
        ->and(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('confirmada');

    $r = ($this->bloquear)(['cancelar_reservas' => true])->assertCreated();

    expect($r->json('data.reservas_canceladas'))->toBe(1)
        ->and(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('cancelada')
        ->and(enCondominio($this->condominio, fn () => Cuota::count()))->toBe(0);
    Mail::assertQueued(ReservaActualizadaMail::class, fn ($m) => $m->titulo === 'Tu reserva fue cancelada por la administración');
});

it('valida las horas y no bloquea días pasados', function () {
    ($this->bloquear)(['inicio' => '12:00', 'fin' => '10:00'])->assertStatus(422)->assertJsonPath('error.code', 'HORAS_INVERTIDAS');
    ($this->bloquear)(['fin' => null])->assertStatus(422)->assertJsonPath('error.code', 'HORAS_INCOMPLETAS');
    ($this->bloquear)(['fecha' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_PASADA');
    ($this->bloquear)(['motivo' => ''])->assertStatus(422);
});

it('la administración cancela una reserva con motivo y avisa', function () {
    $mia = ($this->reservar)()->assertCreated();
    $id = $mia->json('data.id');

    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$id}/cancelar", [])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$id}/cancelar", ['motivo' => 'El salón se usará para una asamblea'])->assertOk();

    expect(($this->residente)()->getJson('/api/v1/mis-reservas')->json('data.0'))->toMatchArray(['estado' => 'cancelada', 'motivo' => 'El salón se usará para una asamblea']);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$id}/cancelar", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_CANCELABLE');
});

it('solo la administración ve la agenda y bloquea', function () {
    ($this->guardia)()->getJson('/api/v1/areas-comunes/agenda')->assertForbidden();
    ($this->residente)()->postJson('/api/v1/areas-comunes/bloqueos', [])->assertForbidden();
    ($this->guardia)()->deleteJson('/api/v1/areas-comunes/bloqueos/1')->assertForbidden();
});

it('otro condominio no ve ni quita los bloqueos', function () {
    $b = ($this->bloquear)()->assertCreated();
    $otro = Condominio::factory()->create();
    [, $tokenO] = usuarioConToken($otro, Rol::Administrador);
    cambiarDeUsuario();

    $this->withToken($tokenO)->withHeader('X-Condominio-Id', (string) $otro->id)->deleteJson('/api/v1/areas-comunes/bloqueos/'.$b->json('data.id'))->assertNotFound();
    expect(enCondominio($this->condominio, fn () => BloqueoArea::count()))->toBe(1);
});
