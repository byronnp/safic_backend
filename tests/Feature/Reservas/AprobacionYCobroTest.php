<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Mail\ReservaActualizadaMail;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;
use App\Modules\Reservas\Services\VencimientosDeReservas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create();
    [$this->user, $token, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenA] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $tokenT] = usuarioConToken($this->condominio, Rol::Tesorero);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($token);
    $this->admin = ($this->como)($tokenA);
    $this->tesorero = ($this->como)($tokenT);

    $this->area = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Salón comunal', 'cantidad' => 1, 'reservable' => true, 'requiere_aprobacion' => true]));
    enCondominio($this->condominio, fn () => ReglaArea::create([
        'amenidad_id' => $this->area->id, 'abre' => '08:00', 'cierra' => '22:00', 'duracion_min_horas' => 2, 'duracion_max_horas' => 4, 'anticipacion_min_horas' => 24,
        'anticipacion_max_dias' => 60, 'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'costo' => '50.00', 'garantia' => '100.00',
    ]));
    $this->fecha = hoyLocal()->addDays(5)->toDateString();
    $this->reservar = fn (array $extra = []) => ($this->residente)()->postJson('/api/v1/mis-reservas', $extra + [
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => $this->fecha, 'inicio' => '10:00', 'horas' => 2, 'acepta_reglamento' => true,
    ]);
    $this->sinAprobacion = fn () => enCondominio($this->condominio, fn () => $this->area->forceFill(['requiere_aprobacion' => false])->save());
    $this->cuotas = fn () => enCondominio($this->condominio, fn () => Cuota::query()->where('concepto', 'extraordinaria')->orderBy('id')->get());
});

it('una reserva sin aprobación se cobra al instante: uso y garantía en la cuenta de la unidad', function () {
    ($this->sinAprobacion)();
    $r = ($this->reservar)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['estado' => 'confirmada', 'por_pagar' => '150.00'])
        ->and($r->json('data.pagar_antes_de'))->toBe(hoyLocal()->addDays(4)->format('Y-m-d').'T10:00');
    $cuotas = ($this->cuotas)();
    expect($cuotas)->toHaveCount(2)
        ->and($cuotas->pluck('monto')->map(fn ($m) => (string) $m)->all())->toBe(['50.00', '100.00'])
        ->and($cuotas[0]->detalle)->toStartWith('Uso Salón comunal')
        ->and($cuotas[1]->detalle)->toStartWith('Garantía Salón comunal')
        ->and($cuotas[0]->unidad_id)->toBe($this->unidad->id);
});

it('un área gratis se confirma sin cobro', function () {
    ($this->sinAprobacion)();
    enCondominio($this->condominio, fn () => ReglaArea::query()->update(['costo' => '0.00', 'garantia' => '0.00']));

    $r = ($this->reservar)()->assertCreated();

    expect($r->json('data.por_pagar'))->toBe('0.00')->and($r->json('data.pagar_antes_de'))->toBeNull()->and(($this->cuotas)())->toHaveCount(0);
});

it('con aprobación queda pendiente sin cobro; la administración la ve, la aprueba y recién ahí se cobra', function () {
    $mia = ($this->reservar)()->assertCreated()->assertJsonPath('data.estado', 'pendiente');
    expect(($this->cuotas)())->toHaveCount(0);

    $lista = ($this->admin)()->getJson('/api/v1/areas-comunes/solicitudes')->assertOk()->json('data');
    expect($lista)->toHaveCount(1)->and($lista[0])->toMatchArray(['id' => $mia->json('data.id'), 'area' => 'Salón comunal', 'unidad' => 'A-102', 'costo' => '50.00', 'garantia' => '100.00']);

    ($this->admin)()->postJson('/api/v1/areas-comunes/reservas/'.$mia->json('data.id').'/aprobar')->assertOk();

    expect(($this->cuotas)())->toHaveCount(2);
    $despues = ($this->residente)()->getJson('/api/v1/mis-reservas')->json('data.0');
    expect($despues)->toMatchArray(['estado' => 'confirmada', 'por_pagar' => '150.00']);
    Mail::assertQueued(ReservaActualizadaMail::class, fn ($m) => $m->titulo === 'Tu reserva fue aprobada');
    ($this->admin)()->postJson('/api/v1/areas-comunes/reservas/'.$mia->json('data.id').'/aprobar')->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_PENDIENTE');
});

it('rechazar pide un motivo, libera el horario y avisa al residente', function () {
    $mia = ($this->reservar)()->assertCreated();
    $id = $mia->json('data.id');

    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$id}/rechazar", [])->assertStatus(422);
    ($this->admin)()->postJson("/api/v1/areas-comunes/reservas/{$id}/rechazar", ['motivo' => 'Ese día hay mantenimiento'])->assertOk();

    $lista = ($this->residente)()->getJson('/api/v1/mis-reservas')->json('data.0');
    expect($lista)->toMatchArray(['estado' => 'rechazada', 'motivo' => 'Ese día hay mantenimiento']);
    Mail::assertQueued(ReservaActualizadaMail::class, fn ($m) => $m->titulo === 'Tu solicitud de reserva fue rechazada');
    ($this->reservar)()->assertCreated();   // el horario quedó libre
});

it('una solicitud sin respuesta vence a las 48 horas y libera el horario', function () {
    ($this->reservar)()->assertCreated();

    $this->travelTo(now()->addHours(47));
    expect(enCondominio($this->condominio, fn () => app(VencimientosDeReservas::class)->procesar()))->toBe(['vencidas' => 0, 'canceladas' => 0]);

    $this->travelTo(now()->addHours(2));
    expect(enCondominio($this->condominio, fn () => app(VencimientosDeReservas::class)->procesar()))->toBe(['vencidas' => 1, 'canceladas' => 0]);
    expect(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('vencida');
    Mail::assertQueued(ReservaActualizadaMail::class, fn ($m) => $m->titulo === 'Tu solicitud de reserva venció');
});

it('una reserva sin pagar se cancela sola a las 24 horas y anula el cobro', function () {
    ($this->sinAprobacion)();
    ($this->reservar)()->assertCreated();

    // Antes del límite no pasa nada
    $this->travelTo(CarbonImmutable::parse($this->fecha.' 09:00', 'America/Guayaquil')->subDay());
    expect(enCondominio($this->condominio, fn () => app(VencimientosDeReservas::class)->procesar())['canceladas'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse($this->fecha.' 10:30', 'America/Guayaquil')->subDay());
    expect(enCondominio($this->condominio, fn () => app(VencimientosDeReservas::class)->procesar())['canceladas'])->toBe(1);

    expect(($this->cuotas)())->toHaveCount(0)
        ->and(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('cancelada');
    Mail::assertQueued(ReservaActualizadaMail::class, fn ($m) => $m->titulo === 'Tu reserva se canceló por falta de pago');
});

it('una reserva pagada no se cancela por falta de pago', function () {
    ($this->sinAprobacion)();
    ($this->reservar)()->assertCreated();
    enCondominio($this->condominio, fn () => Cuota::query()->update(['pagado' => DB::raw('monto')]));

    $this->travelTo(CarbonImmutable::parse($this->fecha.' 10:30', 'America/Guayaquil')->subDay());

    expect(enCondominio($this->condominio, fn () => app(VencimientosDeReservas::class)->procesar())['canceladas'])->toBe(0)
        ->and(enCondominio($this->condominio, fn () => Reserva::first()->estado))->toBe('confirmada');
    $this->travelBack();
    expect(($this->residente)()->getJson('/api/v1/mis-reservas')->json('data.0.por_pagar'))->toBe('0.00');
});

it('al cancelar el residente se anula lo que no pagó', function () {
    ($this->sinAprobacion)();
    $r = ($this->reservar)()->assertCreated();

    ($this->residente)()->postJson('/api/v1/mis-reservas/'.$r->json('data.id').'/cancelar')->assertOk();

    expect(($this->cuotas)())->toHaveCount(0);
});

it('solo la administración responde solicitudes', function () {
    $r = ($this->reservar)()->assertCreated();
    $id = $r->json('data.id');

    ($this->tesorero)()->getJson('/api/v1/areas-comunes/solicitudes')->assertForbidden();
    ($this->residente)()->postJson("/api/v1/areas-comunes/reservas/{$id}/aprobar")->assertForbidden();
    ($this->tesorero)()->postJson("/api/v1/areas-comunes/reservas/{$id}/rechazar", ['motivo' => 'No'])->assertForbidden();
});

it('el comando recorre los condominios', function () {
    $this->artisan('reservas:vencer')->assertSuccessful();
});
