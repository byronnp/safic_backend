<?php

use App\Core\Permissions\Rol;
use App\Modules\Garita\Models\AutorizacionVisita;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle', 'latitud' => '-0.180653', 'longitud' => '-78.467834']);
    [, $token, $this->unidad] = residenteDe($this->condominio, 'A-102');
    [, $tokenB, $this->unidadB] = residenteDe($this->condominio, 'B-9');
    [, $tokenG] = usuarioConToken($this->condominio, Rol::Guardia);
    $this->como = fn (string $t) => function () use ($t) {
        cambiarDeUsuario();

        return $this->withToken($t)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->residente = ($this->como)($token);
    $this->vecino = ($this->como)($tokenB);
    $this->guardia = ($this->como)($tokenG);
    $this->fecha = hoyLocal()->addDays(2)->toDateString();
    $this->autorizar = fn (array $extra = [], $como = null) => ($como ?? $this->residente)()->postJson('/api/v1/mis-visitas', $extra + [
        'unidad_id' => $this->unidad->id, 'visitante' => 'Juan Pérez', 'tipo' => 'unica', 'fecha_desde' => $this->fecha, 'hora_desde' => '15:00', 'hora_hasta' => '20:00',
    ]);
});

it('autoriza una visita de una vez y entrega su QR', function () {
    $r = ($this->autorizar)(['placa' => 'pdq-3321', 'documento' => '1712345678'])->assertCreated();

    expect($r->json('data'))->toMatchArray(['visitante' => 'Juan Pérez', 'unidad' => 'A-102', 'tipo' => 'unica', 'placa' => 'PDQ-3321', 'fecha_desde' => $this->fecha, 'fecha_hasta' => $this->fecha, 'hora_desde' => '15:00', 'hora_hasta' => '20:00'])
        ->and($r->json('data.token'))->toStartWith($this->condominio->id.'-')->and(strlen($r->json('data.token')))->toBeGreaterThan(40);
});

it('la cédula del visitante se guarda cifrada y no sale en la respuesta', function () {
    $r = ($this->autorizar)(['documento' => '1712345678'])->assertCreated();

    expect(json_encode($r->json()))->not->toContain('1712345678')
        ->and(str_contains((string) DB::table('autorizaciones_visita')->value('visitante_documento'), '1712345678'))->toBeFalse()
        ->and(enCondominio($this->condominio, fn () => AutorizacionVisita::first()->visitante_documento))->toBe('1712345678');
});

it('una visita recurrente exige días y no pasa de un año', function () {
    $ok = ($this->autorizar)(['tipo' => 'recurrente', 'dias_semana' => [5, 1, 3, 3], 'fecha_hasta' => hoyLocal()->addMonths(3)->toDateString(), 'hora_desde' => '07:00', 'hora_hasta' => '16:00'])->assertCreated();
    expect($ok->json('data.dias_semana'))->toBe([1, 3, 5]);

    ($this->autorizar)(['tipo' => 'recurrente', 'dias_semana' => []])->assertStatus(422)->assertJsonPath('error.code', 'SIN_DIAS');
    ($this->autorizar)(['tipo' => 'recurrente', 'dias_semana' => [1], 'fecha_hasta' => hoyLocal()->addMonths(14)->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'VIGENCIA_MUY_LARGA');
});

it('valida fechas, horas y formatos', function () {
    ($this->autorizar)(['fecha_desde' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_PASADA');
    ($this->autorizar)(['hora_desde' => '20:00', 'hora_hasta' => '15:00'])->assertStatus(422)->assertJsonPath('error.code', 'HORAS_INVERTIDAS');
    ($this->autorizar)(['tipo' => 'recurrente', 'dias_semana' => [1], 'fecha_desde' => $this->fecha, 'fecha_hasta' => hoyLocal()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHAS_INVERTIDAS');
    ($this->autorizar)(['visitante' => 'Ab'])->assertStatus(422);
    ($this->autorizar)(['placa' => 'ZZZ'])->assertStatus(422);
    ($this->autorizar)(['documento' => '12 34'])->assertStatus(422);
    ($this->autorizar)(['tipo' => 'diaria'])->assertStatus(422);
});

it('solo autoriza para una unidad propia y tiene un tope de visitas activas', function () {
    ($this->autorizar)(['unidad_id' => $this->unidadB->id])->assertStatus(403)->assertJsonPath('error.code', 'UNIDAD_NO_ES_SUYA');

    foreach (range(1, AutorizacionVisita::MAXIMO_ACTIVAS) as $i) {
        enCondominio($this->condominio, fn () => AutorizacionVisita::create([
            'unidad_id' => $this->unidad->id, 'token' => "t{$i}", 'visitante_nombre' => "V {$i}", 'tipo' => 'unica', 'fecha_desde' => $this->fecha, 'fecha_hasta' => $this->fecha, 'hora_desde' => '10:00', 'hora_hasta' => '11:00',
        ]));
    }
    ($this->autorizar)()->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_AUTORIZACIONES');
    ($this->autorizar)(['unidad_id' => $this->unidadB->id], $this->vecino)->assertCreated();   // otra unidad tiene su propio cupo
});

it('lista solo las activas de mis unidades, la más próxima primero', function () {
    ($this->autorizar)(['fecha_desde' => hoyLocal()->addDays(5)->toDateString(), 'visitante' => 'Tarde'])->assertCreated();
    ($this->autorizar)(['visitante' => 'Pronto'])->assertCreated();
    $ajena = ($this->autorizar)(['unidad_id' => $this->unidadB->id, 'visitante' => 'Del vecino'], $this->vecino)->assertCreated();
    enCondominio($this->condominio, fn () => AutorizacionVisita::create(['unidad_id' => $this->unidad->id, 'token' => 'vieja', 'visitante_nombre' => 'Vieja', 'tipo' => 'unica', 'fecha_desde' => hoyLocal()->subDays(9)->toDateString(), 'fecha_hasta' => hoyLocal()->subDays(9)->toDateString(), 'hora_desde' => '10:00', 'hora_hasta' => '11:00']));

    $r = ($this->residente)()->getJson('/api/v1/mis-visitas')->assertOk();

    expect(collect($r->json('data.visitas'))->pluck('visitante')->all())->toBe(['Pronto', 'Tarde'])
        ->and($r->json('data.unidades'))->toHaveCount(1)
        ->and(json_encode($r->json()))->not->toContain($ajena->json('data.token'));
});

it('revocar apaga el QR; la autorización de otra unidad no existe para mí', function () {
    $mia = ($this->autorizar)()->assertCreated();
    $ajena = ($this->autorizar)(['unidad_id' => $this->unidadB->id], $this->vecino)->assertCreated();

    ($this->residente)()->postJson('/api/v1/mis-visitas/'.$ajena->json('data.id').'/revocar')->assertNotFound();
    ($this->residente)()->postJson('/api/v1/mis-visitas/'.$mia->json('data.id').'/revocar')->assertOk();

    expect(($this->residente)()->getJson('/api/v1/mis-visitas')->json('data.visitas'))->toBe([]);
    cambiarDeUsuario();
    $this->getJson('/api/v1/publico/visitas/'.$mia->json('data.token'))->assertOk()->assertJsonPath('data.estado', 'revocada');
    expect(enCondominio($this->condominio, fn () => AutorizacionVisita::find($ajena->json('data.id'))->revocada_en))->toBeNull();
});

it('la página pública del QR muestra lo mínimo, sin sesión', function () {
    $mia = ($this->autorizar)(['placa' => 'PDQ-3321', 'documento' => '1712345678'])->assertCreated();
    cambiarDeUsuario();

    $r = $this->getJson('/api/v1/publico/visitas/'.$mia->json('data.token'))->assertOk();

    expect($r->json('data'))->toMatchArray(['estado' => 'activa', 'visitante' => 'Juan', 'unidad' => 'A-102', 'tipo' => 'unica', 'hora_desde' => '15:00'])
        ->and($r->json('data.condominio.nombre'))->toBe('Jardines del Valle')
        ->and($r->json('data.condominio.latitud'))->toBe('-0.180653')
        ->and(json_encode($r->json()))->not->toContain('PDQ-3321')->not->toContain('1712345678')->not->toContain('Pérez');
});

it('un token inventado, de otro formato o de otro condominio responde 404', function () {
    $this->getJson('/api/v1/publico/visitas/nada')->assertNotFound();
    $this->getJson('/api/v1/publico/visitas/999999-'.str_repeat('a', 40))->assertNotFound();
    $mia = ($this->autorizar)()->assertCreated();
    $otro = Condominio::factory()->create();
    cambiarDeUsuario();
    $this->getJson('/api/v1/publico/visitas/'.$otro->id.'-'.explode('-', $mia->json('data.token'), 2)[1])->assertNotFound();
});

it('solo el residente autoriza visitas', function () {
    ($this->guardia)()->getJson('/api/v1/mis-visitas')->assertForbidden();
    ($this->autorizar)([], $this->guardia)->assertForbidden();
});
