<?php

use App\Core\Permissions\Rol;
use App\Core\Realtime\ActualizacionEnVivo;
use App\Core\Realtime\EnVivo;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    [$this->residente, $this->tokenResidente] = residenteDe($this->condominio, 'A-1');
    [, $this->tokenGuardia] = usuarioConToken($this->condominio, Rol::Guardia);
    $this->suscribir = function (string $token, string $canal) {
        cambiarDeUsuario();

        return $this->withToken($token)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$canal}"]);
    };
});

it('la cuenta escucha su propio canal y recibe la firma del canal', function () {
    $canal = EnVivo::canalDeUsuario($this->condominio->id, $this->residente->id);

    ($this->suscribir)($this->tokenResidente, $canal)->assertOk()->assertJsonStructure(['auth']);
});

it('nadie escucha el canal de otra cuenta, aunque sea del mismo condominio', function () {
    $canal = EnVivo::canalDeUsuario($this->condominio->id, $this->residente->id + 1000);

    ($this->suscribir)($this->tokenResidente, $canal)->assertForbidden();
    // El guardia tampoco puede escuchar al residente
    ($this->suscribir)($this->tokenGuardia, EnVivo::canalDeUsuario($this->condominio->id, $this->residente->id))->assertForbidden();
});

it('quien no pertenece al condominio no escucha ningún canal de él', function () {
    $otro = Condominio::factory()->create();
    [$ajeno, $tokenAjeno] = usuarioConToken($otro, Rol::Guardia);

    ($this->suscribir)($tokenAjeno, EnVivo::canalDeUsuario($this->condominio->id, $ajeno->id))->assertForbidden();
    ($this->suscribir)($tokenAjeno, EnVivo::canalDeGarita($this->condominio->id))->assertForbidden();
});

it('el canal de la garita exige el permiso de registrar en garita', function () {
    $canal = EnVivo::canalDeGarita($this->condominio->id);

    ($this->suscribir)($this->tokenGuardia, $canal)->assertOk();
    ($this->suscribir)($this->tokenResidente, $canal)->assertForbidden();
});

it('sin sesión no se autoriza ningún canal', function () {
    cambiarDeUsuario();

    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-condominio.'.$this->condominio->id.'.garita'])->assertUnauthorized();
});

it('un canal que no existe se rechaza', function () {
    ($this->suscribir)($this->tokenResidente, 'condominio.'.$this->condominio->id.'.otro')->assertForbidden();
});

it('el aviso solo lleva el recurso y el id, sin datos de negocio', function () {
    EnVivo::aGarita($this->condominio->id, 'solicitudes-ingreso', 7);

    Event::assertDispatched(ActualizacionEnVivo::class, function (ActualizacionEnVivo $e): bool {
        return $e->broadcastWith() === ['recurso' => 'solicitudes-ingreso', 'id' => 7]
            && $e->broadcastAs() === 'actualizacion'
            && array_map(fn ($c) => $c->name, $e->broadcastOn()) === ['private-condominio.'.$this->condominio->id.'.garita'];
    });
});
