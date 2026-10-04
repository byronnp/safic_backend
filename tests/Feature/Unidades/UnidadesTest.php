<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Actions\GuardarConfiguracionCobroAction;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;

/**
 * Cobro del condominio para las pruebas que dependen del método.
 */
function cobroPor(Condominio $condominio, string $metodo): void
{
    enCondominio($condominio, fn () => app(GuardarConfiguracionCobroAction::class)->execute([
        'metodo' => $metodo,
        'cuota_general' => $metodo === 'general' ? '80.00' : null,
        'presupuesto_mensual' => $metodo === 'alicuota' ? '10000.00' : null,
        'valores_tipo' => [],
        'dia_vencimiento' => 10,
        'aplica_desde' => now()->addMonth()->startOfMonth()->toDateString(),
    ]));
}

beforeEach(function () {
    $this->condominio = Condominio::factory()->create(['total_unidades' => 3]);
    [, $token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
});

describe('listar', function () {
    beforeEach(function () {
        enCondominio($this->condominio, function () {
            $torre = Bloque::factory()->create(['nombre' => 'Torre A']);
            $this->torre = $torre;
            Unidad::factory()->create(['codigo' => 'A-102', 'bloque_id' => $torre->id]);
            Unidad::factory()->create(['codigo' => 'A-101', 'bloque_id' => $torre->id]);
            Unidad::factory()->parqueadero()->create(['codigo' => 'P-01']);
        });
    });

    it('lista paginado y ordenado por código', function () {
        ($this->api)()->getJson('/api/v1/unidades')
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'A-101')
            ->assertJsonPath('data.0.bloque.nombre', 'Torre A')
            ->assertJsonPath('data.0.area_m2', '84.00')
            ->assertJsonPath('data.0.estado', 'vacia')
            ->assertJsonPath('meta.pagination.total', 3);
    });

    it('filtra por tipo, bloque y código', function () {
        ($this->api)()->getJson('/api/v1/unidades?tipo=parqueadero')->assertJsonCount(1, 'data');
        ($this->api)()->getJson('/api/v1/unidades?bloque_id='.$this->torre->id)->assertJsonCount(2, 'data');
        ($this->api)()->getJson('/api/v1/unidades?buscar=a-10')->assertJsonCount(2, 'data');
    });

    it('resume el avance frente al total contratado', function () {
        enCondominio($this->condominio, fn () => Unidad::query()->update(['alicuota' => '0.6200']));

        ($this->api)()->getJson('/api/v1/unidades/resumen')
            ->assertOk()
            ->assertExactJson(['data' => [
                'registradas' => 2,
                'total_contratadas' => 3,
                'suma_alicuotas' => '1.8600',
                'metodo_cobro' => 'general',
                'cuota_general' => null,
            ]]);
    });

    it('trae la cuota general cuando el condominio cobra un valor general', function () {
        cobroPor($this->condominio, 'general');

        ($this->api)()->getJson('/api/v1/unidades/resumen')
            ->assertJsonPath('data.metodo_cobro', 'general')
            ->assertJsonPath('data.cuota_general', '80.00');
    });
});

describe('crear', function () {
    it('crea la unidad con el código en mayúsculas y el propietario como responsable', function () {
        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => ' a-102 ', 'tipo' => 'departamento', 'piso' => 1, 'area_m2' => 84])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'A-102')
            ->assertJsonPath('data.responsable_pago', 'propietario')
            ->assertJsonPath('data.bloque', null);
    });

    it('no repite el código, salvo el de una unidad eliminada', function () {
        $id = enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'A-102'])->id);

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-102', 'tipo' => 'casa', 'area_m2' => 120])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.codigo.0', 'Ya existe una unidad con ese código.');

        ($this->api)()->deleteJson("/api/v1/unidades/{$id}")->assertNoContent();

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-102', 'tipo' => 'casa', 'area_m2' => 120])
            ->assertCreated();
    });

    it('rechaza un bloque de otro condominio', function () {
        $otro = Condominio::factory()->create();
        $bloque = enCondominio($otro, fn () => Bloque::factory()->create());

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100, 'bloque_id' => $bloque->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bloque_id', 'error.fields');
    });

    it('exige el permiso unidades.editar', function () {
        [, $token] = usuarioConToken($this->condominio, Rol::Guardia);

        $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
            ->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100])
            ->assertForbidden();
    });
});

describe('total de unidades contratadas', function () {
    beforeEach(function () {
        enCondominio($this->condominio, fn () => Unidad::factory()->count(3)->create());
    });

    it('no registra más unidades que las contratadas', function () {
        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'X-1', 'tipo' => 'local', 'area_m2' => 40])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIMITE_UNIDADES')
            ->assertJsonPath('error.details', ['total' => 3, 'registradas' => 3]);
    });

    it('los parqueaderos y bodegas no cuentan', function () {
        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'P-1', 'tipo' => 'parqueadero', 'area_m2' => 12.5])->assertCreated();
        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'B-1', 'tipo' => 'bodega', 'area_m2' => 4])->assertCreated();
    });

    it('pasar de parqueadero a departamento exige cupo', function () {
        $id = enCondominio($this->condominio, fn () => Unidad::factory()->parqueadero()->create()->id);

        ($this->api)()->patchJson("/api/v1/unidades/{$id}", ['tipo' => 'departamento'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIMITE_UNIDADES');
    });

    it('eliminar una unidad libera su cupo', function () {
        $id = enCondominio($this->condominio, fn () => Unidad::query()->value('id'));

        ($this->api)()->deleteJson("/api/v1/unidades/{$id}")->assertNoContent();
        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'X-1', 'tipo' => 'local', 'area_m2' => 40])->assertCreated();
    });
});

describe('montos según el método de cobro', function () {
    it('con valor general no acepta cuota ni valor personalizado', function () {
        cobroPor($this->condominio, 'general');

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100, 'cuota_mensual' => 50, 'valor_personalizado' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cuota_mensual', 'valor_personalizado'], 'error.fields');
    });

    it('por alícuota exige la alícuota y admite valor personalizado', function () {
        cobroPor($this->condominio, 'alicuota');

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alicuota', 'error.fields');

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100, 'alicuota' => 0.62, 'valor_personalizado' => 75.5])
            ->assertCreated()
            ->assertJsonPath('data.alicuota', '0.6200')
            ->assertJsonPath('data.valor_personalizado', '75.50');
    });

    it('por unidad exige la cuota mensual', function () {
        cobroPor($this->condominio, 'unidad');

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cuota_mensual', 'error.fields');

        ($this->api)()->postJson('/api/v1/unidades', ['codigo' => 'A-1', 'tipo' => 'casa', 'area_m2' => 100, 'cuota_mensual' => 90])
            ->assertCreated()
            ->assertJsonPath('data.cuota_mensual', '90.00');
    });
});

describe('editar y ver', function () {
    it('edita solo los campos enviados', function () {
        $id = enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'A-1', 'piso' => 1])->id);

        ($this->api)()->patchJson("/api/v1/unidades/{$id}", ['piso' => 3, 'responsable_pago' => 'inquilino'])
            ->assertOk()
            ->assertJsonPath('data.codigo', 'A-1')
            ->assertJsonPath('data.piso', 3)
            ->assertJsonPath('data.responsable_pago', 'inquilino');

        ($this->api)()->getJson("/api/v1/unidades/{$id}")->assertOk()->assertJsonPath('data.piso', 3);
    });
});
