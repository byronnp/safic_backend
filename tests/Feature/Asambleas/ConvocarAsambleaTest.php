<?php

use App\Core\Permissions\Rol;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Comunicacion\Mail\AnuncioMail;
use App\Modules\Comunicacion\Models\Anuncio;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->condominio = Condominio::factory()->create();
    [, $tokenAdmin] = usuarioConToken($this->condominio, Rol::Administrador);
    [, $this->tokenResidente, $this->unidad] = residenteDe($this->condominio, 'A-101', 'propietario', ['alicuota' => '5.0000']);
    residenteDe($this->condominio, 'A-102', 'propietario', ['alicuota' => '5.0000']);
    $this->tokenAdmin = $tokenAdmin;
    $this->como = function (?string $token = null) {
        cambiarDeUsuario();

        return $this->withToken($token ?? $this->tokenAdmin)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    };
    $this->crear = function (int $dias = 45, bool $conPunto = true) {
        $id = ($this->como)()->postJson('/api/v1/asambleas', [
            'titulo' => 'Asamblea ordinaria 2026', 'tipo' => 'ordinaria', 'modalidad' => 'presencial',
            'fecha_hora' => hoyLocal()->addDays($dias)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal',
        ])->assertCreated()->json('data.id');
        if ($conPunto) {
            ($this->como)()->postJson("/api/v1/asambleas/{$id}/puntos", ['titulo' => 'Informe de la administración', 'tipo' => 'informativo'])->assertCreated();
        }

        return $id;
    };
});

it('convoca: publica el anuncio con confirmación, pasa a convocada y avisa por correo', function () {
    $id = ($this->crear)();

    $r = ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();

    expect($r->json('data.estado'))->toBe('convocada')->and($r->json('data.puede_cancelar'))->toBeTrue()->and($r->json('data.puede_convocar'))->toBeFalse();
    $anuncio = enCondominio($this->condominio, fn () => Anuncio::findOrFail(Asamblea::findOrFail($id)->anuncio_id));
    expect($anuncio->titulo)->toBe('Convocatoria: Asamblea ordinaria 2026')->and($anuncio->requiere_confirmacion)->toBeTrue()->and($anuncio->fijado)->toBeTrue()
        ->and($anuncio->mensaje)->toContain('1. Informe de la administración')->toContain('segunda convocatoria');
    Mail::assertQueued(AnuncioMail::class, 2);
});

it('no convoca sin orden del día, sin 8 días hábiles ni dos veces', function () {
    $sinPuntos = ($this->crear)(45, false);
    ($this->como)()->postJson("/api/v1/asambleas/{$sinPuntos}/convocar")->assertStatus(422)->assertJsonPath('error.code', 'SIN_ORDEN_DEL_DIA');

    $corta = ($this->crear)(3);
    ($this->como)()->postJson("/api/v1/asambleas/{$corta}/convocar")->assertStatus(422)->assertJsonPath('error.code', 'SIN_ANTICIPACION');

    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_YA_CONVOCADA');
});

it('una convocada ya no se edita', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();

    ($this->como)()->patchJson("/api/v1/asambleas/{$id}", ['titulo' => 'Otro título', 'tipo' => 'ordinaria', 'modalidad' => 'presencial', 'fecha_hora' => hoyLocal()->addDays(46)->format('Y-m-d').'T19:00', 'lugar' => 'Salón comunal'])->assertStatus(409);
});

it('el residente no convoca ni cancela', function () {
    $id = ($this->crear)();
    ($this->como)($this->tokenResidente)->postJson("/api/v1/asambleas/{$id}/convocar")->assertForbidden();
    ($this->como)($this->tokenResidente)->postJson("/api/v1/asambleas/{$id}/cancelar", ['motivo' => 'Prueba'])->assertForbidden();
});

it('muestra el seguimiento de la convocatoria y reenvía a los pendientes', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
    $anuncioId = enCondominio($this->condominio, fn () => Asamblea::findOrFail($id)->anuncio_id);
    ($this->como)($this->tokenResidente)->postJson("/api/v1/mis-avisos/{$anuncioId}/confirmar")->assertOk();

    $r = ($this->como)()->getJson("/api/v1/asambleas/{$id}/convocatoria")->assertOk();
    expect($r->json('data.unidades'))->toBe(2)->and($r->json('data.confirmaron'))->toBe(1)->and($r->json('data.porcentaje_confirmaron'))->toBe(50)
        ->and($r->json('data.pendientes'))->toHaveCount(1)->and($r->json('data.pendientes.0.codigo'))->toBe('A-102');

    Mail::fake();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocatoria/reenviar")->assertOk()->assertJsonPath('data.unidades', 1);
    Mail::assertQueued(AnuncioMail::class, fn ($m) => $m->recordatorio === true);
});

it('descarga la constancia en PDF y no la da si no se convocó', function () {
    $id = ($this->crear)();
    ($this->como)()->getJson("/api/v1/asambleas/{$id}/convocatoria")->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_SIN_CONVOCAR');
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();

    $r = ($this->como)()->get("/api/v1/asambleas/{$id}/constancia.pdf")->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf')->and(str_starts_with($r->getContent(), '%PDF'))->toBeTrue();
});

it('cancela una convocada: avisa el motivo y retira la convocatoria', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
    $anuncioId = enCondominio($this->condominio, fn () => Asamblea::findOrFail($id)->anuncio_id);

    ($this->como)()->postJson("/api/v1/asambleas/{$id}/cancelar", ['motivo' => ''])->assertStatus(422);
    $r = ($this->como)()->postJson("/api/v1/asambleas/{$id}/cancelar", ['motivo' => 'No se alcanzó el salón'])->assertOk();

    expect($r->json('data.estado'))->toBe('cancelada')->and($r->json('data.motivo_cancelacion'))->toBe('No se alcanzó el salón')->and($r->json('data.puede_cancelar'))->toBeFalse();
    enCondominio($this->condominio, function () use ($anuncioId) {
        expect(Anuncio::findOrFail($anuncioId)->fijado)->toBeFalse()
            ->and(Anuncio::where('titulo', 'like', 'Asamblea cancelada:%')->where('mensaje', 'like', '%No se alcanzó el salón%')->exists())->toBeTrue();
    });
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/cancelar", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'ASAMBLEA_NO_CANCELABLE');
});

it('no se cancela un borrador', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/cancelar", ['motivo' => 'Prueba'])->assertStatus(409);
});

it('recuerda 3 días y 2 horas antes, una sola vez cada uno', function () {
    $id = ($this->crear)();
    ($this->como)()->postJson("/api/v1/asambleas/{$id}/convocar")->assertOk();
    $anuncioId = enCondominio($this->condominio, fn () => Asamblea::findOrFail($id)->anuncio_id);
    ($this->como)($this->tokenResidente)->postJson("/api/v1/mis-avisos/{$anuncioId}/confirmar")->assertOk();
    Mail::fake();

    // Faltan 45 días: todavía no toca
    $this->artisan('asambleas:recordar')->assertSuccessful();
    Mail::assertNothingQueued();

    // Faltan 2 días y medio: recordatorio de 3 días solo a la unidad que no confirmó
    enCondominio($this->condominio, fn () => Asamblea::whereKey($id)->update(['fecha_hora' => now()->addHours(60)]));
    $this->artisan('asambleas:recordar')->assertSuccessful();
    Mail::assertQueued(AnuncioMail::class, 1);
    $this->artisan('asambleas:recordar')->assertSuccessful();
    Mail::assertQueued(AnuncioMail::class, 1);

    // Faltan 90 minutos: recordatorio a todas
    enCondominio($this->condominio, fn () => Asamblea::whereKey($id)->update(['fecha_hora' => now()->addMinutes(90)]));
    $this->artisan('asambleas:recordar')->assertSuccessful();
    Mail::assertQueued(AnuncioMail::class, 3);
    $this->artisan('asambleas:recordar')->assertSuccessful();
    Mail::assertQueued(AnuncioMail::class, 3);
});
