<?php

use App\Core\Permissions\Rol;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Reservas\Models\ReglaArea;
use App\Modules\Reservas\Models\Reserva;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [$this->user, $token, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($token);
    $this->vecino = ($this->como)($tokenB);
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    $this->guardia = ($this->como)($tokenG);

    $this->area = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'Salón comunal', 'cantidad' => 1, 'reservable' => true]));
    $this->reglas = fn (array $extra = []) => enCondominio($this->condominio, fn () => ReglaArea::updateOrCreate(['amenidad_id' => $this->area->id], $extra + [
        'abre' => '08:00', 'cierra' => '22:00', 'duracion_min_horas' => 2, 'duracion_max_horas' => 4, 'anticipacion_min_horas' => 24, 'anticipacion_max_dias' => 60,
        'capacidad' => 60, 'reservas_por_unidad_mes' => null, 'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'costo' => '50.00', 'garantia' => '100.00',
    ]));
    ($this->reglas)();
    $this->fecha = hoyLocal()->addDays(5)->toDateString();
    $this->reservar = fn (array $extra = [], $como = null) => ($como ?? $this->residente)()->postJson('/api/v1/mis-reservas', $extra + [
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => $this->fecha, 'inicio' => '10:00', 'horas' => 2, 'acepta_reglamento' => true,
    ]);
});

it('reserva sin aprobación: queda confirmada con el costo y la garantía de las reglas', function () {
    $r = ($this->reservar)()->assertCreated();

    expect($r->json('data'))->toMatchArray(['estado' => 'confirmada', 'inicio' => '10:00', 'fin' => '12:00', 'costo' => '50.00', 'garantia' => '100.00', 'unidad' => 'A-102', 'puede_cancelar' => true]);
});

it('con aprobación del administrador queda pendiente', function () {
    enCondominio($this->condominio, fn () => $this->area->forceFill(['requiere_aprobacion' => true])->save());

    ($this->reservar)()->assertCreated()->assertJsonPath('data.estado', 'pendiente');
});

it('dos reservas no se cruzan, y una cancelada libera el horario', function () {
    $primera = ($this->reservar)()->assertCreated();

    ($this->reservar)(['unidad_id' => $this->unidadB->id, 'inicio' => '10:00'], $this->vecino)->assertStatus(409)->assertJsonPath('error.code', 'HORARIO_OCUPADO');
    ($this->reservar)(['unidad_id' => $this->unidadB->id, 'inicio' => '12:00'], $this->vecino)->assertCreated();

    ($this->residente)()->postJson('/api/v1/mis-reservas/'.$primera->json('data.id').'/cancelar')->assertOk();
    ($this->reservar)(['unidad_id' => $this->unidadB->id, 'inicio' => '10:00', 'horas' => 2], $this->vecino)->assertCreated();
});

it('la disponibilidad marca libres, ocupadas y cerradas', function () {
    ($this->reservar)()->assertCreated();

    $r = ($this->residente)()->getJson("/api/v1/mis-reservas/areas/{$this->area->id}/disponibilidad?desde={$this->fecha}&dias=1")->assertOk();
    $estados = collect($r->json('data.0.franjas'))->pluck('estado', 'inicio');

    expect($estados['10:00'])->toBe('ocupada')->and($estados['08:00'])->toBe('libre')->and($estados['12:00'])->toBe('libre')
        ->and($r->json('data.0.franjas'))->toHaveCount(7);

    // Mañana mismo: dentro de las 24 h de anticipación, cerrada
    $hoy = ($this->residente)()->getJson('/api/v1/mis-reservas/areas/'.$this->area->id.'/disponibilidad?desde='.hoyLocal()->toDateString().'&dias=1')->assertOk();
    expect(collect($hoy->json('data.0.franjas'))->pluck('estado')->unique()->all())->toBe(['cerrada']);
});

it('exige aceptar el reglamento y una unidad propia', function () {
    ($this->reservar)(['acepta_reglamento' => false])->assertStatus(422)->assertJsonPath('error.code', 'REGLAMENTO_NO_ACEPTADO');
    ($this->reservar)(['unidad_id' => $this->unidadB->id])->assertStatus(403)->assertJsonPath('error.code', 'UNIDAD_NO_ES_SUYA');
});

it('respeta horario, duración, día y anticipación del área', function () {
    ($this->reservar)(['inicio' => '07:00'])->assertStatus(422)->assertJsonPath('error.code', 'HORARIO_NO_VALIDO');
    ($this->reservar)(['inicio' => '11:00'])->assertStatus(422)->assertJsonPath('error.code', 'HORARIO_NO_VALIDO');   // no cae en una franja
    ($this->reservar)(['horas' => 6])->assertStatus(422)->assertJsonPath('error.code', 'HORARIO_NO_VALIDO');
    ($this->reservar)(['inicio' => '20:00', 'horas' => 4])->assertStatus(422)->assertJsonPath('error.code', 'HORARIO_NO_VALIDO'); // pasa del cierre
    ($this->reservar)(['fecha' => hoyLocal()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'ANTICIPACION_INSUFICIENTE');
    ($this->reservar)(['fecha' => hoyLocal()->addDays(90)->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'DEMASIADO_ADELANTE');

    $otroDia = hoyLocal()->addDays(5)->isoWeekday();
    ($this->reglas)(['dias_semana' => array_values(array_diff([1, 2, 3, 4, 5, 6, 7], [$otroDia]))]);
    ($this->reservar)()->assertStatus(422)->assertJsonPath('error.code', 'DIA_NO_HABILITADO');
});

it('aplica el tope de reservas por unidad al mes, por área', function () {
    ($this->reglas)(['reservas_por_unidad_mes' => 1]);

    ($this->reservar)()->assertCreated();
    ($this->reservar)(['inicio' => '14:00'])->assertStatus(409)->assertJsonPath('error.code', 'TOPE_MENSUAL');
    // La otra unidad tiene su propio tope
    ($this->reservar)(['unidad_id' => $this->unidadB->id, 'inicio' => '14:00'], $this->vecino)->assertCreated();
});

it('no reserva un área en mantenimiento ni sin reglas', function () {
    enCondominio($this->condominio, fn () => $this->area->forceFill(['mantenimiento_hasta' => hoyLocal()->addDays(10)->toDateString()])->save());
    ($this->reservar)()->assertStatus(409)->assertJsonPath('error.code', 'AREA_EN_MANTENIMIENTO');

    $sinReglas = enCondominio($this->condominio, fn () => CondominioAmenidad::create(['nombre' => 'BBQ', 'cantidad' => 1, 'reservable' => true]));
    ($this->reservar)(['amenidad_id' => $sinReglas->id])->assertStatus(409)->assertJsonPath('error.code', 'AREA_SIN_REGLAS');
});

it('lista mis reservas con las próximas primero y solo cancela las futuras y propias', function () {
    $mia = ($this->reservar)()->assertCreated();
    $ajena = ($this->reservar)(['unidad_id' => $this->unidadB->id, 'inicio' => '14:00'], $this->vecino)->assertCreated();
    $pasada = enCondominio($this->condominio, fn () => Reserva::create([
        'amenidad_id' => $this->area->id, 'unidad_id' => $this->unidad->id, 'fecha' => hoyLocal()->subDays(3)->toDateString(), 'inicio' => '10:00', 'fin' => '12:00',
        'estado' => 'confirmada', 'costo' => '50.00', 'garantia' => '0.00', 'reglamento_aceptado_en' => now(),
    ]));

    $lista = ($this->residente)()->getJson('/api/v1/mis-reservas')->assertOk()->json('data');
    expect(array_column($lista, 'id'))->toBe([$mia->json('data.id'), $pasada->id])
        ->and($lista[1]['puede_cancelar'])->toBeFalse();

    ($this->residente)()->postJson("/api/v1/mis-reservas/{$pasada->id}/cancelar")->assertStatus(409)->assertJsonPath('error.code', 'RESERVA_NO_CANCELABLE');
    ($this->residente)()->postJson('/api/v1/mis-reservas/'.$ajena->json('data.id').'/cancelar')->assertNotFound();
});

it('un condominio no ve las áreas de otro y quien no es residente no reserva', function () {
    $otro = Condominio::factory()->create();
    $ajena = enCondominio($otro, fn () => CondominioAmenidad::create(['nombre' => 'Piscina', 'cantidad' => 1, 'reservable' => true]));
    ($this->residente)()->getJson("/api/v1/mis-reservas/areas/{$ajena->id}/disponibilidad")->assertNotFound();

    ($this->guardia)()->getJson('/api/v1/mis-reservas/areas')->assertForbidden();
    ($this->guardia)()->postJson('/api/v1/mis-reservas', [])->assertForbidden();

    $r = ($this->residente)()->getJson('/api/v1/mis-reservas/areas')->assertOk();
    expect(collect($r->json('data.areas'))->pluck('nombre')->all())->toBe(['Salón comunal'])
        ->and($r->json('data.areas.0'))->toMatchArray(['estado' => 'disponible', 'mantenimiento_hasta' => null])
        ->and($r->json('data.unidades'))->toHaveCount(1);
});
