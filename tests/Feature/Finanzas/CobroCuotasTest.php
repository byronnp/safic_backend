<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\GuardarConfiguracionCobroAction;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['total_unidades' => 50]);
    [$this->admin, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $this->proximo = enCondominio($this->condominio, fn () => CarbonImmutable::parse(app(Calendario::class)->hoy())->startOfMonth()->addMonth());
    $this->desde = $this->proximo->format('Y-m');

    // Los cambios van primero: con "+" gana el valor de la izquierda
    $this->general = fn (array $cambios = []) => $cambios + [
        'metodo' => 'general', 'cuota_general' => '80.00', 'dia_vencimiento' => 10, 'aplica_desde' => $this->desde,
    ];
    $this->guardar = fn (array $datos) => ($this->api)()->putJson('/api/v1/cobro', $datos);
});

it('sin configuración propone el valor general y cuenta las unidades por tipo', function () {
    enCondominio($this->condominio, function () {
        Unidad::factory()->count(2)->create(['tipo' => 'departamento']);
        Unidad::factory()->create(['tipo' => 'casa']);
    });

    ($this->api)()->getJson('/api/v1/cobro')->assertOk()
        ->assertJsonPath('data.configurado', false)
        ->assertJsonPath('data.metodo', 'general')
        ->assertJsonPath('data.dia_vencimiento', 10)
        ->assertJsonPath('data.unidades.por_tipo.departamento', 2)
        ->assertJsonPath('data.unidades.por_tipo.casa', 1)
        ->assertJsonPath('data.unidades.con_cupo', 3)
        ->assertJsonPath('data.historial', []);
});

it('guarda el valor general y lo devuelve', function () {
    ($this->guardar)(($this->general)())->assertOk()
        ->assertJsonPath('message', 'Cambios guardados.')
        ->assertJsonPath('data.configurado', true)
        ->assertJsonPath('data.metodo', 'general')
        ->assertJsonPath('data.cuota_general', '80.00')
        ->assertJsonPath('data.aplica_desde', $this->desde);
});

it('por tipo guarda los valores y al volver a otro método los quita', function () {
    ($this->guardar)(($this->general)([
        'metodo' => 'tipo', 'cuota_general' => null,
        'valores_tipo' => [['tipo' => 'departamento', 'valor' => '80.00'], ['tipo' => 'casa', 'valor' => '120.00']],
    ]))->assertOk()->assertJsonCount(2, 'data.valores_tipo')->assertJsonPath('data.cuota_general', null);

    ($this->guardar)(($this->general)(['metodo' => 'general']))->assertOk()->assertJsonPath('data.valores_tipo', []);
});

it('valida la cuota, el vencimiento y el mes desde el que aplica', function () {
    $mesActual = $this->proximo->subMonth()->format('Y-m');
    $lejano = $this->proximo->addMonths(12)->format('Y-m');

    ($this->guardar)(($this->general)(['cuota_general' => '0']))->assertStatus(422)->assertJsonPath('error.fields.cuota_general.0', 'La cuota debe ser mayor que 0.');
    ($this->guardar)(($this->general)(['cuota_general' => null]))->assertStatus(422)->assertJsonPath('error.fields.cuota_general.0', 'Escribe la cuota mensual.');
    ($this->guardar)(($this->general)(['dia_vencimiento' => 29]))->assertStatus(422)->assertJsonPath('error.fields.dia_vencimiento.0', 'El día de vencimiento es del 1 al 28, o 0 para el último día del mes.');
    ($this->guardar)(($this->general)(['aplica_desde' => $mesActual]))->assertStatus(422)->assertJsonPath('error.fields.aplica_desde.0', 'El cambio aplica desde el mes siguiente: las cuotas ya emitidas no cambian.');
    ($this->guardar)(($this->general)(['aplica_desde' => $lejano]))->assertStatus(422)->assertJsonPath('error.fields.aplica_desde.0', 'Elige un mes de los próximos 12.');
    ($this->guardar)(($this->general)(['metodo' => 'tipo', 'valores_tipo' => [['tipo' => 'casa', 'valor' => '1'], ['tipo' => 'casa', 'valor' => '2']]]))->assertStatus(422);
    ($this->guardar)(($this->general)(['metodo' => 'otro']))->assertStatus(422);
});

it('no pasa a alícuota ni a por unidad si a alguna unidad le falta el dato', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['alicuota' => null, 'cuota_mensual' => null]));

    ($this->guardar)(($this->general)(['metodo' => 'alicuota', 'cuota_general' => null, 'presupuesto_mensual' => '12000.00']))
        ->assertStatus(409)->assertJsonPath('error.code', 'UNIDADES_SIN_ALICUOTA');
    ($this->guardar)(($this->general)(['metodo' => 'unidad', 'cuota_general' => null]))
        ->assertStatus(409)->assertJsonPath('error.code', 'UNIDADES_SIN_CUOTA');

    // No quedó nada a medias
    ($this->api)()->getJson('/api/v1/cobro')->assertJsonPath('data.configurado', false);
});

it('pasa a alícuota cuando todas las unidades tienen alícuota', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['alicuota' => '1.2500']));

    ($this->guardar)(($this->general)(['metodo' => 'alicuota', 'cuota_general' => null, 'presupuesto_mensual' => '12000.00']))
        ->assertOk()->assertJsonPath('data.presupuesto_mensual', '12000.00')->assertJsonPath('data.cuota_general', null);
});

it('el historial dice qué cambió, quién y deja primero lo más reciente', function () {
    enCondominio($this->condominio, fn () => app(GuardarConfiguracionCobroAction::class)->execute([
        'metodo' => 'general', 'cuota_general' => '75.00', 'dia_vencimiento' => 5, 'aplica_desde' => $this->proximo->toDateString(),
    ]));
    ($this->guardar)(($this->general)(['cuota_general' => '80.00', 'dia_vencimiento' => 10]))->assertOk();

    $historial = ($this->api)()->getJson('/api/v1/cobro')->json('data.historial');

    expect($historial)->toHaveCount(2)
        ->and($historial[0]['quien'])->toBe($this->admin->name)
        ->and($historial[0]['inicial'])->toBeFalse()
        ->and(collect($historial[0]['cambios'])->firstWhere('campo', 'cuota_general'))->toMatchArray(['antes' => '75.00', 'despues' => '80.00'])
        ->and(collect($historial[0]['cambios'])->firstWhere('campo', 'dia_vencimiento'))->toMatchArray(['antes' => '5', 'despues' => '10'])
        ->and($historial[1]['inicial'])->toBeTrue()
        // Hecho sin una persona del condominio (alta o consola): no se muestra como un usuario
        ->and($historial[1]['quien'])->toBe('Sistema');
});

it('los cambios de valor por tipo también quedan en el historial', function () {
    ($this->guardar)(($this->general)(['metodo' => 'tipo', 'cuota_general' => null, 'valores_tipo' => [['tipo' => 'casa', 'valor' => '100.00']]]))->assertOk();
    $this->travel(5)->seconds(); // cambios en el mismo segundo se juntan en uno solo
    ($this->guardar)(($this->general)(['metodo' => 'tipo', 'cuota_general' => null, 'valores_tipo' => [['tipo' => 'casa', 'valor' => '120.00']]]))->assertOk();

    $campos = collect(($this->api)()->getJson('/api/v1/cobro')->json('data.historial.0.cambios'));

    expect($campos->firstWhere('campo', 'valor_tipo:casa'))->toMatchArray(['antes' => '100.00', 'despues' => '120.00']);
});

it('solo quien edita el condominio puede ver y cambiar el cobro', function () {
    foreach ([Rol::Guardia, Rol::Residente] as $rol) {
        [, $token] = usuarioConToken($this->condominio, $rol);
        $api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

        $api()->getJson('/api/v1/cobro')->assertForbidden();
        $api()->putJson('/api/v1/cobro', ($this->general)())->assertForbidden();
    }
});

it('no mezcla el cobro ni su historial entre condominios', function () {
    ($this->guardar)(($this->general)())->assertOk();
    $otro = Condominio::factory()->create();
    [, $token] = usuarioConToken($otro);
    cambiarDeUsuario();

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $otro->id)
        ->getJson('/api/v1/cobro')->assertOk()
        ->assertJsonPath('data.configurado', false)
        ->assertJsonPath('data.historial', []);
});
