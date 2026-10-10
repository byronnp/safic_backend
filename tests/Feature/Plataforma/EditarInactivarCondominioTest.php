<?php

use App\Core\Audit\RegistroBitacora;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Unidad;

beforeEach(function () {
    sembrarCatalogos();
    $this->condominio = Condominio::factory()->create(['nombre' => 'Conjunto Los Arupos', 'estado' => 'activo', 'total_unidades' => 10, 'ruc' => '1790016919001']);
    $this->como = function (Rol $rol) {
        $u = User::factory()->dePlataforma($rol)->create();
        cambiarDeUsuario();

        return $this->withToken(auth('api')->tokenById($u->id));
    };
    $this->ruta = "/api/v1/plataforma/condominios/{$this->condominio->id}";
});

it('el super admin edita los datos de un condominio y queda en la bitácora', function () {
    $r = ($this->como)(Rol::SuperAdmin)->patchJson($this->ruta, [
        'nombre' => 'Conjunto Los Arupos II', 'razon_social' => 'Arupos S.A.', 'telefono' => '0223456789',
        'email_contacto' => 'admin@arupos.ec', 'total_unidades' => 40, 'valor_unidad' => '1.50',
    ])->assertOk();

    expect($r->json('data'))->toMatchArray(['nombre' => 'Conjunto Los Arupos II', 'razon_social' => 'Arupos S.A.', 'total_unidades' => 40])
        ->and($r->json('data.contacto'))->toMatchArray(['telefono' => '0223456789', 'email' => 'admin@arupos.ec'])
        ->and($r->json('data.mensualidad'))->toBe('60.00');
    expect(RegistroBitacora::query()->where('entidad', 'condominio')->where('entidad_id', (string) $this->condominio->id)->exists())->toBeTrue();
});

it('solo el super admin edita o inactiva: soporte y cobranza no', function () {
    foreach ([Rol::Soporte, Rol::Cobranza] as $rol) {
        ($this->como)($rol)->patchJson($this->ruta, ['nombre' => 'Otro nombre'])->assertForbidden();
        ($this->como)($rol)->postJson("{$this->ruta}/inactivar", ['motivo' => 'Falta de pago'])->assertForbidden();
    }
    expect($this->condominio->fresh()->nombre)->toBe('Conjunto Los Arupos');
});

it('el administrador del condominio no usa las rutas de plataforma', function () {
    [, $token] = usuarioConToken($this->condominio);

    $this->withToken($token)->patchJson($this->ruta, ['nombre' => 'X Y'])->assertStatus(403);
});

it('valida: RUC repetido, total menor a las unidades registradas, ubicación incoherente', function () {
    Condominio::factory()->create(['ruc' => '1790011674001']);
    enCondominio($this->condominio, fn () => Unidad::factory()->count(3)->create());
    $api = fn () => ($this->como)(Rol::SuperAdmin);

    $api()->patchJson($this->ruta, ['ruc' => '1790011674001'])->assertStatus(422);
    $api()->patchJson($this->ruta, ['ruc' => '123'])->assertStatus(422);
    $api()->patchJson($this->ruta, ['total_unidades' => 2])->assertStatus(422)->assertJsonPath('error.code', 'UNIDADES_REGISTRADAS');
    $api()->patchJson($this->ruta, ['total_unidades' => 3])->assertOk();
    $api()->patchJson($this->ruta, ['nombre' => ''])->assertStatus(422);

    // Puede conservar su propio RUC
    $api()->patchJson($this->ruta, ['ruc' => '1790016919001', 'nombre' => 'Mismo RUC'])->assertOk();
});

it('inactivar suspende el acceso al instante, sin borrar nada, y reactivar devuelve el estado previo', function () {
    [, $token] = usuarioConToken($this->condominio);
    enCondominio($this->condominio, fn () => Unidad::factory()->create());
    $this->condominio->forceFill(['estado' => 'solo_lectura'])->save();
    $api = fn () => ($this->como)(Rol::SuperAdmin);

    $api()->postJson("{$this->ruta}/inactivar", [])->assertStatus(422);
    $api()->postJson("{$this->ruta}/inactivar", ['motivo' => 'Contrato terminado'])->assertOk()->assertJsonPath('data.estado', 'suspendido');
    $api()->postJson("{$this->ruta}/inactivar", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'CONDOMINIO_YA_INACTIVO');

    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/unidades')
        ->assertForbidden()->assertJsonPath('error.code', 'CONDOMINIO_NO_PERMITIDO');
    expect(enCondominio($this->condominio, fn () => Unidad::count()))->toBe(1);

    $registro = RegistroBitacora::query()->where('entidad', 'condominio')->latest('id')->first();
    expect($registro->valores_nuevos)->toMatchArray(['estado' => 'suspendido', 'motivo' => 'Contrato terminado']);

    $api()->postJson("{$this->ruta}/reactivar", ['motivo' => 'Se regularizó el pago'])->assertOk()->assertJsonPath('data.estado', 'solo_lectura');
    $api()->postJson("{$this->ruta}/reactivar", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'CONDOMINIO_NO_INACTIVO');

    cambiarDeUsuario();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/unidades')->assertOk();
});
