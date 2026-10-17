<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Actions\ActualizarRestriccionesAction;
use App\Modules\Finanzas\Actions\EnviarRecordatoriosAction;
use App\Modules\Finanzas\Mail\RecordatorioCuotaMail;
use App\Modules\Finanzas\Mail\RestriccionAreasMail;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\ConvenioPago;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Finanzas\Models\Pago;
use App\Modules\Finanzas\Models\RecordatorioMora;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Persona;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create(['nombre' => 'Jardines del Valle']);
    $this->usuarios = [];
    foreach (['admin' => Rol::Administrador, 'tesorero' => Rol::Tesorero, 'contador' => Rol::Contador] as $clave => $rol) {
        [$u] = usuarioConToken($this->condominio, $rol);
        if ($rol === Rol::Contador) {
            $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        }
        $this->usuarios[$clave] = $u;
    }
    $this->como = function (string $clave) {
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($this->usuarios[$clave]->id))->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    // A-101 debe 4 meses; B-202 un mes; C-303 está al día
    [, , $this->a] = residenteDe($this->condominio, 'A-101');
    [, , $this->b] = residenteDe($this->condominio, 'B-202');
    [, , $this->c] = residenteDe($this->condominio, 'C-303');
    foreach ([4, 3, 2, 1] as $m) {
        cuotaDe($this->condominio, $this->a, $m);
    }
    cuotaDe($this->condominio, $this->b, 1);
    cuotaDe($this->condominio, $this->c, 1, '80.00', '80.00');
    $this->servicio = fn () => enCondominio($this->condominio, fn () => app(MorosidadService::class));
});

it('lista a quien debe cuotas vencidas, del más atrasado al menos, con la cartera por antigüedad', function () {
    $r = ($this->como)('contador')->getJson('/api/v1/finanzas/morosidad')->assertOk();

    expect(array_column($r->json('data'), 'codigo'))->toBe(['A-101', 'B-202'])
        ->and($r->json('data.0'))->toMatchArray(['cuotas_vencidas' => 4, 'meses_vencidos' => 4, 'saldo_vencido' => '320.00', 'en_mora' => true])
        ->and($r->json('data.0.situacion.tipo'))->toBe('ninguna')
        ->and($r->json('meta.resumen.cartera_vencida'))->toMatchArray(['saldo' => '400.00', 'unidades' => 2])
        ->and($r->json('meta.resumen.total_unidades'))->toBe(3)
        ->and(collect($r->json('meta.resumen.tramos'))->firstWhere('tramo', 'al_dia')['unidades'])->toBe(1)
        ->and($r->json('meta.conteos'))->toMatchArray(['todas' => 2, 'en_mora' => 2])
        ->and($r->json('meta.reglas'))->toBe(['dias_gracia' => 5, 'meses_restriccion' => 3, 'dias_aviso_restriccion' => 5]);

    expect(array_column(($this->como)('contador')->getJson('/api/v1/finanzas/morosidad?buscar=b-2')->json('data'), 'codigo'))->toBe(['B-202']);
});

it('la cuota recién vencida, dentro de los días de gracia, debe pero no está en mora', function () {
    enCondominio($this->condominio, fn () => Cuota::query()->where('unidad_id', $this->b->id)->update(['vence_el' => hoyLocal()->subDays(3)->toDateString()]));

    $b = collect(($this->como)('admin')->getJson('/api/v1/finanzas/morosidad')->json('data'))->firstWhere('codigo', 'B-202');

    expect($b['en_mora'])->toBeFalse()->and($b['dias_atraso'])->toBe(3);
});

it('el recordatorio manual avisa por correo una vez al día y cuenta a quien no tiene correo', function () {
    enCondominio($this->condominio, fn () => Persona::query()->whereHas('ocupaciones', fn ($q) => $q->where('unidad_id', $this->b->id))->update(['email' => null]));

    $r = ($this->como)('tesorero')->postJson('/api/v1/finanzas/morosidad/recordatorios')->assertOk();
    expect($r->json('data'))->toMatchArray(['enviados' => 1, 'sin_correo' => 1, 'ya_avisadas' => 0]);
    Mail::assertQueued(RecordatorioCuotaMail::class, 1);

    $otra = ($this->como)('tesorero')->postJson('/api/v1/finanzas/morosidad/recordatorios')->assertOk();
    expect($otra->json('data'))->toMatchArray(['enviados' => 0, 'ya_avisadas' => 1]);

    $a = collect(($this->como)('tesorero')->getJson('/api/v1/finanzas/morosidad')->json('data'))->firstWhere('codigo', 'A-101');
    expect($a['situacion']['tipo'])->toBe('recordatorio');
});

it('los recordatorios automáticos salen 3 días antes, el día y cada 15 días de atraso, sin repetirse', function () {
    $hoy = hoyLocal();
    enCondominio($this->condominio, function () use ($hoy) {
        Cuota::query()->delete();
        Cuota::create(['unidad_id' => $this->a->id, 'periodo' => $hoy->startOfMonth()->toDateString(), 'concepto' => 'ordinaria', 'monto' => '80.00', 'pagado' => '0.00', 'vence_el' => $hoy->addDays(3)->toDateString()]);
        Cuota::create(['unidad_id' => $this->b->id, 'periodo' => $hoy->startOfMonth()->toDateString(), 'concepto' => 'ordinaria', 'monto' => '80.00', 'pagado' => '0.00', 'vence_el' => $hoy->toDateString()]);
        Cuota::create(['unidad_id' => $this->c->id, 'periodo' => $hoy->subMonths(2)->startOfMonth()->toDateString(), 'concepto' => 'ordinaria', 'monto' => '80.00', 'pagado' => '0.00', 'vence_el' => $hoy->subDays(30)->toDateString()]);
    });

    $enviar = fn () => enCondominio($this->condominio, fn () => app(EnviarRecordatoriosAction::class)->automaticos());

    expect($enviar())->toBe(3)
        ->and($enviar())->toBe(0);
    expect(enCondominio($this->condominio, fn () => RecordatorioMora::query()->orderBy('unidad_id')->pluck('tipo')->all()))->toBe(['antes', 'dia', 'atraso']);
    Mail::assertQueued(RecordatorioCuotaMail::class, 3);
});

it('notifica a quien llega a los meses de deuda y la restricción entra en vigor después del aviso', function () {
    $actualizar = fn () => enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute());

    expect($actualizar())->toBe(['notificadas' => 1, 'activadas' => 0, 'levantadas' => 0]);
    Mail::assertQueued(RestriccionAreasMail::class, fn ($m) => $m->unidad === 'A-101' && $m->meses === 4);

    $r = enCondominio($this->condominio, fn () => RestriccionMora::firstOrFail());
    expect($r->estado)->toBe('notificada')->and($r->restringe_desde->toDateString())->toBe(hoyLocal()->addDays(5)->toDateString())
        ->and(($this->servicio)()->restringida($this->a->id))->toBeFalse();

    // Pasa el plazo de aviso
    enCondominio($this->condominio, fn () => RestriccionMora::query()->update(['restringe_desde' => hoyLocal()->toDateString()]));
    expect($actualizar())->toMatchArray(['notificadas' => 0, 'activadas' => 1]);
    expect(enCondominio($this->condominio, fn () => app(MorosidadService::class)->restringida($this->a->id)))->toBeTrue();

    $a = collect(($this->como)('admin')->getJson('/api/v1/finanzas/morosidad?filtro=restringidas')->json('data'))->firstWhere('codigo', 'A-101');
    expect($a['situacion']['tipo'])->toBe('restringida');
});

it('no restringe a quien debe menos meses que el límite; y el límite se puede cambiar', function () {
    $actualizar = fn () => enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute()['notificadas']);

    enCondominio($this->condominio, fn () => ConfiguracionFinanzas::create(['meses_restriccion' => 5]));
    expect($actualizar())->toBe(0);

    enCondominio($this->condominio, fn () => ConfiguracionFinanzas::query()->update(['meses_restriccion' => 1]));
    expect($actualizar())->toBe(2); // A-101 y B-202 ya deben un mes
});

it('pagar levanta la restricción al aprobar el pago', function () {
    enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute());
    enCondominio($this->condominio, fn () => RestriccionMora::query()->update(['estado' => 'activa']));
    // Con solo 2 meses de deuda ya no corresponde restringir
    enCondominio($this->condominio, fn () => Cuota::query()->where('unidad_id', $this->a->id)->orderBy('periodo')->limit(2)->update(['pagado' => '80.00']));
    $pago = enCondominio($this->condominio, fn () => Pago::create(['unidad_id' => $this->a->id, 'metodo' => 'efectivo', 'monto' => '80.00', 'fecha_pago' => hoyLocal()->toDateString(), 'estado' => 'pendiente']));

    ($this->como)('admin')->postJson("/api/v1/pagos/{$pago->id}/aprobar")->assertOk();

    $r = enCondominio($this->condominio, fn () => RestriccionMora::firstOrFail());
    expect($r->estado)->toBe('levantada')->and($r->motivo_levantamiento)->toBe('pago');
});

it('un convenio al día levanta la restricción y deja de contar como mora', function () {
    enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute());

    $r = ($this->como)('tesorero')->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 4, 'primera_cuota_el' => hoyLocal()->addDays(10)->toDateString()])->assertCreated();
    expect($r->json('data'))->toMatchArray(['monto_total' => '320.00', 'cuotas' => 4, 'estado' => 'activo', 'al_dia' => true, 'cuotas_cubiertas' => 0])
        ->and(array_column($r->json('data.plan'), 'monto'))->toBe(['80.00', '80.00', '80.00', '80.00']);

    expect(enCondominio($this->condominio, fn () => RestriccionMora::firstOrFail()->motivo_levantamiento))->toBe('convenio');

    $a = collect(($this->como)('admin')->getJson('/api/v1/finanzas/morosidad')->json('data'))->firstWhere('codigo', 'A-101');
    expect($a['en_mora'])->toBeFalse()->and($a['situacion'])->toMatchArray(['tipo' => 'convenio', 'cuota' => 1, 'de' => 4, 'al_dia' => true]);
});

it('el convenio avanza con los pagos aprobados y se atrasa si no se cumple', function () {
    $hoy = hoyLocal();
    $id = ($this->como)('admin')->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 2, 'primera_cuota_el' => $hoy->toDateString()])->assertCreated()->json('data.id');

    $lista = fn () => collect(($this->como)('admin')->getJson('/api/v1/finanzas/convenios')->json('data'))->firstWhere('id', $id);
    expect($lista()['al_dia'])->toBeFalse(); // la primera cuota vence hoy y no se pagó

    enCondominio($this->condominio, fn () => Pago::create(['unidad_id' => $this->a->id, 'metodo' => 'efectivo', 'monto' => '160.00', 'fecha_pago' => $hoy->toDateString(), 'estado' => 'aprobado']));
    expect($lista())->toMatchArray(['al_dia' => true, 'cuotas_cubiertas' => 1, 'pagado' => '160.00']);
});

it('un convenio exige deuda vencida, uno solo activo y se cancela con motivo', function () {
    $api = fn () => ($this->como)('admin');
    $api()->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->c->id, 'cuotas' => 3, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertStatus(409)->assertJsonPath('error.code', 'SIN_DEUDA_VENCIDA');
    $api()->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 1, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertStatus(422);
    $api()->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 3, 'primera_cuota_el' => hoyLocal()->subDay()->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_PASADA');

    $id = $api()->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 3, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertCreated()->json('data.id');
    $api()->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 3, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertStatus(409)->assertJsonPath('error.code', 'CONVENIO_ACTIVO');

    $api()->postJson("/api/v1/finanzas/convenios/{$id}/cancelar", [])->assertStatus(422);
    $api()->postJson("/api/v1/finanzas/convenios/{$id}/cancelar", ['motivo' => 'Incumplió las dos primeras cuotas'])->assertOk()->assertJsonPath('data.estado', 'cancelado');
    $api()->postJson("/api/v1/finanzas/convenios/{$id}/cancelar", ['motivo' => 'Otra vez'])->assertStatus(409);
});

it('el convenio pagado por completo se da por cumplido', function () {
    ($this->como)('admin')->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->b->id, 'cuotas' => 2, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertCreated();
    enCondominio($this->condominio, fn () => Pago::create(['unidad_id' => $this->b->id, 'metodo' => 'efectivo', 'monto' => '80.00', 'fecha_pago' => hoyLocal()->toDateString(), 'estado' => 'aprobado']));

    enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute());

    expect(enCondominio($this->condominio, fn () => ConvenioPago::firstOrFail()->estado))->toBe('cumplido');
});

it('se levanta a mano la restricción con motivo', function () {
    enCondominio($this->condominio, fn () => app(ActualizarRestriccionesAction::class)->execute());

    ($this->como)('admin')->postJson("/api/v1/finanzas/morosidad/restricciones/{$this->a->id}/levantar", [])->assertStatus(422);
    ($this->como)('admin')->postJson("/api/v1/finanzas/morosidad/restricciones/{$this->a->id}/levantar", ['motivo' => 'Acuerdo de la directiva'])->assertOk();
    ($this->como)('admin')->postJson("/api/v1/finanzas/morosidad/restricciones/{$this->a->id}/levantar", ['motivo' => 'Otra vez'])->assertNotFound();

    expect(enCondominio($this->condominio, fn () => RestriccionMora::firstOrFail()->motivo_levantamiento))->toBe('Acuerdo de la directiva');
});

it('quien está en mora no puede ocupar un cargo de la directiva', function () {
    $persona = enCondominio($this->condominio, fn () => Persona::query()->whereHas('ocupaciones', fn ($q) => $q->where('unidad_id', $this->a->id))->firstOrFail());
    expect(enCondominio($this->condominio, fn () => app(MorosidadService::class)->personaEnMora($persona->id)))->toBeTrue();

    $ajena = enCondominio($this->condominio, fn () => Persona::query()->whereHas('ocupaciones', fn ($q) => $q->where('unidad_id', $this->c->id))->firstOrFail());
    expect(enCondominio($this->condominio, fn () => app(MorosidadService::class)->personaEnMora($ajena->id)))->toBeFalse();

    ($this->como)('admin')->postJson('/api/v1/directiva/presidente', ['persona_id' => $persona->id, 'acta' => 'Acta 1', 'periodo_hasta' => hoyLocal()->addYear()->toDateString()])
        ->assertStatus(422)->assertJsonPath('error.code', 'PERSONA_EN_MORA');
});

it('solo la administración gestiona; el contador y el residente solo ven o nada', function () {
    ($this->como)('contador')->getJson('/api/v1/finanzas/morosidad')->assertOk();
    ($this->como)('contador')->postJson('/api/v1/finanzas/morosidad/recordatorios')->assertForbidden();
    ($this->como)('contador')->postJson('/api/v1/finanzas/convenios', ['unidad_id' => $this->a->id, 'cuotas' => 3, 'primera_cuota_el' => hoyLocal()->toDateString()])->assertForbidden();
});

it('cada condominio ve su propia cartera', function () {
    $otro = Condominio::factory()->create();
    [$ajeno] = usuarioConToken($otro);
    cambiarDeUsuario();

    $r = $this->withToken(auth('api')->tokenById($ajeno->id))->withHeader('X-Condominio-Id', (string) $otro->id)->getJson('/api/v1/finanzas/morosidad')->assertOk();
    expect($r->json('data'))->toBe([])->and($r->json('meta.resumen.cartera_vencida.unidades'))->toBe(0);
});
