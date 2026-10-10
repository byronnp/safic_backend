<?php

use App\Core\Audit\RegistroBitacora;
use App\Core\Auth\Services\DesafioDobleFactor;
use App\Core\Auth\Services\Totp;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Models\Condominio;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Cache;

const CLAVE_2FA = 'Clave-Segura-2026';

/** Código válido ahora para el secreto. */
function codigoAhora(string $secreto, int $pasos = 0): string
{
    return Totp::codigo($secreto, Totp::paso() + $pasos);
}

/** Activa la verificación del usuario por la API y devuelve [secreto, códigos de respaldo]. */
function activarDobleFactor(User $user): array
{
    cambiarDeUsuario();
    $token = auth('api')->tokenById($user->id);
    $api = fn () => test()->withToken($token);

    $secreto = $api()->postJson('/api/v1/auth/2fa/preparar', ['password' => CLAVE_2FA])->assertOk()->json('data.secreto');
    $codigos = $api()->postJson('/api/v1/auth/2fa/confirmar', ['codigo' => codigoAhora($secreto)])->assertOk()->json('data.codigos_respaldo');
    cambiarDeUsuario();

    return [$secreto, $codigos];
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->condominio = Condominio::factory()->create();
    $this->user = User::factory()->miembroDe($this->condominio, Rol::Administrador)->create(['email' => 'maria@ejemplo.ec', 'password' => CLAVE_2FA]);
    $this->conSesion = function (User $u) {
        cambiarDeUsuario();

        return test()->withToken(auth('api')->tokenById($u->id));
    };
    $this->iniciar = fn () => test()->postJson('/api/v1/auth/login', ['email' => 'maria@ejemplo.ec', 'password' => CLAVE_2FA]);
});

it('activar pide la contraseña, un código de la app y entrega 8 códigos de respaldo una sola vez', function () {
    $api = ($this->conSesion)($this->user);

    $api->postJson('/api/v1/auth/2fa/preparar', ['password' => 'otra'])->assertStatus(422)->assertJsonValidationErrors('password', 'error.fields');

    $r = $api->postJson('/api/v1/auth/2fa/preparar', ['password' => CLAVE_2FA])->assertOk();
    expect($r->json('data.secreto'))->toMatch('/^[A-Z2-7]{32}$/')
        ->and($r->json('data.uri'))->toStartWith('otpauth://totp/SAFIC%3Amaria%40ejemplo.ec?secret='.$r->json('data.secreto'));
    expect($this->user->fresh()->tieneDobleFactor())->toBeFalse(); // todavía no confirmada

    $api->postJson('/api/v1/auth/2fa/confirmar', ['codigo' => '000000'])->assertStatus(422)->assertJsonValidationErrors('codigo', 'error.fields');
    $codigos = $api->postJson('/api/v1/auth/2fa/confirmar', ['codigo' => codigoAhora($r->json('data.secreto'))])->assertOk()->json('data.codigos_respaldo');

    expect($codigos)->toHaveCount(8)->and($codigos[0])->toMatch('/^[A-Z2-7]{5}-[A-Z2-7]{5}$/')
        ->and($this->user->fresh()->tieneDobleFactor())->toBeTrue();
    // El secreto y los códigos no salen de la base en claro ni por la API
    expect(DB::table('users')->where('id', $this->user->id)->value('two_factor_secret'))->not->toBe($r->json('data.secreto'));
    ($this->conSesion)($this->user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.doble_factor.activo', true)->assertJsonMissingPath('data.two_factor_secret');
});

it('no se puede preparar ni confirmar si ya está activa, ni confirmar sin preparar', function () {
    ($this->conSesion)($this->user)->postJson('/api/v1/auth/2fa/confirmar', ['codigo' => '123456'])->assertStatus(409)->assertJsonPath('error.code', 'DOBLE_FACTOR_SIN_PREPARAR');

    activarDobleFactor($this->user);

    ($this->conSesion)($this->user)->postJson('/api/v1/auth/2fa/preparar', ['password' => CLAVE_2FA])->assertStatus(409)->assertJsonPath('error.code', 'DOBLE_FACTOR_YA_ACTIVO');
});

it('con la verificación activa el login pide el código y no entrega sesión todavía', function () {
    activarDobleFactor($this->user);

    $r = ($this->iniciar)()->assertOk()
        ->assertJsonPath('data.requiere_2fa', true)
        ->assertJsonMissingPath('data.access_token')
        ->assertCookieMissing('safic_refresh');

    expect($r->json('data.desafio'))->toHaveLength(64)->and($r->json('data.expira_en'))->toBe(300);
});

it('el código correcto completa el login; el mismo código no sirve dos veces', function () {
    [$secreto] = activarDobleFactor($this->user);
    $user = $this->user->fresh();
    $codigo = Totp::codigo($secreto, (int) $user->two_factor_last_step + 1);

    // El paso del código activado ya se gastó; uno posterior sí sirve
    $desafio = ($this->iniciar)()->json('data.desafio');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => Totp::codigo($secreto, (int) $user->two_factor_last_step)])
        ->assertStatus(401)->assertJsonPath('error.code', 'CODIGO_INVALIDO');

    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => $codigo])
        ->assertOk()->assertJsonStructure(['data' => ['access_token', 'usuario' => ['id', 'doble_factor']]])->assertPlainCookie('safic_refresh');

    // Con el desafío ya consumido y el mismo código, no entra
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => $codigo])->assertStatus(401)->assertJsonPath('error.code', 'DESAFIO_INVALIDO');
    $nuevo = ($this->iniciar)()->json('data.desafio');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $nuevo, 'codigo' => $codigo])->assertStatus(401)->assertJsonPath('error.code', 'CODIGO_INVALIDO');
});

it('un código de respaldo sirve una vez', function () {
    [, $codigos] = activarDobleFactor($this->user);

    $desafio = ($this->iniciar)()->json('data.desafio');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => strtolower($codigos[0])])->assertOk()->assertJsonStructure(['data' => ['access_token']]);

    $otro = ($this->iniciar)()->json('data.desafio');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $otro, 'codigo' => $codigos[0]])->assertStatus(401)->assertJsonPath('error.code', 'CODIGO_INVALIDO');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $otro, 'codigo' => $codigos[1]])->assertOk();
    expect($this->user->fresh()->two_factor_recovery_codes)->toHaveCount(6);
});

it('el desafío muere a los 5 intentos fallidos y caduca', function () {
    activarDobleFactor($this->user);
    $desafio = ($this->iniciar)()->json('data.desafio');

    foreach (range(1, DesafioDobleFactor::INTENTOS) as $_) {
        test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => '000000'])->assertStatus(401)->assertJsonPath('error.code', 'CODIGO_INVALIDO');
    }
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => '000000'])->assertStatus(401)->assertJsonPath('error.code', 'DESAFIO_INVALIDO');

    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => str_repeat('a', 64), 'codigo' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'DESAFIO_INVALIDO');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => 'corto', 'codigo' => '123456'])->assertStatus(422);

    Cache::flush();
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'DESAFIO_INVALIDO');
});

it('sin la verificación activa el login sigue igual', function () {
    ($this->iniciar)()->assertOk()->assertJsonStructure(['data' => ['access_token']])->assertJsonMissingPath('data.requiere_2fa');
});

it('el contador no puede trabajar sin activarla, pero sí consultar su contexto y configurarla', function () {
    $contador = User::factory()->miembroDe($this->condominio, Rol::Contador)->create(['email' => 'cont@ejemplo.ec', 'password' => CLAVE_2FA]);
    $conCondominio = fn () => ($this->conSesion)($contador)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $conCondominio()->getJson('/api/v1/finanzas/resumen')->assertForbidden()->assertJsonPath('error.code', 'DOBLE_FACTOR_REQUERIDO');
    $conCondominio()->getJson('/api/v1/me/contexto')->assertOk()->assertJsonPath('data.doble_factor_pendiente', true);

    activarDobleFactor($contador);

    $conCondominio()->getJson('/api/v1/finanzas/resumen')->assertOk();
    $conCondominio()->getJson('/api/v1/me/contexto')->assertOk()->assertJsonPath('data.doble_factor_pendiente', false);
});

it('quien no es contador no queda bloqueado por no tenerla', function () {
    ($this->conSesion)($this->user)->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/usuarios')->assertOk();
});

it('el contador no puede desactivarla; los demás sí, con contraseña y código', function () {
    $contador = User::factory()->miembroDe($this->condominio, Rol::Contador)->create(['password' => CLAVE_2FA]);
    [$secretoC] = activarDobleFactor($contador);
    ($this->conSesion)($contador)->postJson('/api/v1/auth/2fa/desactivar', ['password' => CLAVE_2FA, 'codigo' => codigoAhora($secretoC, 1)])
        ->assertForbidden()->assertJsonPath('error.code', 'DOBLE_FACTOR_OBLIGATORIO');
    expect($contador->fresh()->tieneDobleFactor())->toBeTrue();

    [$secreto] = activarDobleFactor($this->user);
    $api = fn () => ($this->conSesion)($this->user->fresh());
    $api()->postJson('/api/v1/auth/2fa/desactivar', ['password' => 'otra', 'codigo' => codigoAhora($secreto, 1)])->assertStatus(422);
    $api()->postJson('/api/v1/auth/2fa/desactivar', ['password' => CLAVE_2FA, 'codigo' => '000000'])->assertStatus(422);
    $api()->postJson('/api/v1/auth/2fa/desactivar', ['password' => CLAVE_2FA, 'codigo' => codigoAhora($secreto, 1)])->assertOk();

    $fresco = $this->user->fresh();
    expect($fresco->tieneDobleFactor())->toBeFalse()->and($fresco->two_factor_secret)->toBeNull()->and($fresco->two_factor_recovery_codes)->toBeNull();
});

it('regenerar los códigos de respaldo invalida los anteriores', function () {
    [$secreto, $viejos] = activarDobleFactor($this->user);

    $nuevos = ($this->conSesion)($this->user->fresh())->postJson('/api/v1/auth/2fa/codigos', ['password' => CLAVE_2FA, 'codigo' => codigoAhora($secreto, 1)])
        ->assertOk()->json('data.codigos_respaldo');

    expect($nuevos)->toHaveCount(8)->and(array_intersect($nuevos, $viejos))->toBe([]);
    $desafio = ($this->iniciar)()->json('data.desafio');
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => $viejos[0]])->assertStatus(401);
    test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => $nuevos[0]])->assertOk();
});

it('el administrador restablece la verificación del contador, con motivo', function () {
    $contador = User::factory()->miembroDe($this->condominio, Rol::Contador)->create(['password' => CLAVE_2FA]);
    activarDobleFactor($contador);
    $admin = fn () => ($this->conSesion)($this->user)->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    expect(collect($admin()->getJson('/api/v1/usuarios')->json('data'))->firstWhere('id', $contador->id)['doble_factor'])->toBeTrue();

    $admin()->postJson("/api/v1/usuarios/{$contador->id}/doble-factor/restablecer", [])->assertStatus(422);
    $admin()->postJson("/api/v1/usuarios/{$contador->id}/doble-factor/restablecer", ['motivo' => 'Perdió el teléfono'])->assertOk();

    expect($contador->fresh()->tieneDobleFactor())->toBeFalse();
    $admin()->postJson("/api/v1/usuarios/{$contador->id}/doble-factor/restablecer", ['motivo' => 'Otra vez'])->assertStatus(409)->assertJsonPath('error.code', 'DOBLE_FACTOR_NO_ACTIVO');
    // Vuelve a ser obligatoria para trabajar
    ($this->conSesion)($contador->fresh())->withHeader('X-Condominio-Id', (string) $this->condominio->id)->getJson('/api/v1/finanzas/resumen')->assertForbidden();
});

it('el administrador no restablece la suya ni la de otro administrador, ni a alguien de otro condominio', function () {
    activarDobleFactor($this->user);
    $otroAdmin = User::factory()->miembroDe($this->condominio, Rol::Administrador)->create(['password' => CLAVE_2FA]);
    activarDobleFactor($otroAdmin);
    $ajeno = User::factory()->miembroDe(Condominio::factory()->create(), Rol::Contador)->create(['password' => CLAVE_2FA]);
    activarDobleFactor($ajeno);
    $admin = fn () => ($this->conSesion)($this->user->fresh())->withHeader('X-Condominio-Id', (string) $this->condominio->id);

    $admin()->postJson("/api/v1/usuarios/{$this->user->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertStatus(422)->assertJsonPath('error.code', 'RESTABLECIMIENTO_PROPIO');
    $admin()->postJson("/api/v1/usuarios/{$otroAdmin->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertForbidden()->assertJsonPath('error.code', 'SOLO_PLATAFORMA');
    $admin()->postJson("/api/v1/usuarios/{$ajeno->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertNotFound();

    expect($otroAdmin->fresh()->tieneDobleFactor())->toBeTrue()->and($ajeno->fresh()->tieneDobleFactor())->toBeTrue();
});

it('un guardia no restablece nada', function () {
    $contador = User::factory()->miembroDe($this->condominio, Rol::Contador)->create(['password' => CLAVE_2FA]);
    activarDobleFactor($contador);
    $guardia = User::factory()->miembroDe($this->condominio, Rol::Guardia)->create();

    ($this->conSesion)($guardia)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->postJson("/api/v1/usuarios/{$contador->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertForbidden();
});

it('la plataforma restablece la de un administrador y queda en la bitácora', function () {
    activarDobleFactor($this->user);
    $soporte = User::factory()->dePlataforma(Rol::Soporte)->create(['name' => 'Soporte Uno']);

    ($this->conSesion)($soporte)->postJson("/api/v1/plataforma/usuarios/{$this->user->id}/doble-factor/restablecer", ['motivo' => 'Perdió el teléfono y los códigos'])
        ->assertOk();

    expect($this->user->fresh()->tieneDobleFactor())->toBeFalse();
    $registro = RegistroBitacora::query()->where('entidad', 'usuario')->firstOrFail();
    expect($registro->user_nombre)->toBe('Soporte Uno')
        ->and($registro->valores_nuevos)->toMatchArray(['doble_factor' => 'restablecido', 'motivo' => 'Perdió el teléfono y los códigos']);
});

it('la plataforma no restablece la propia, ni sin motivo, ni sin permiso', function () {
    $soporte = User::factory()->dePlataforma(Rol::Soporte)->create(['password' => CLAVE_2FA]);
    activarDobleFactor($soporte);
    $api = fn () => ($this->conSesion)($soporte->fresh());

    $api()->postJson("/api/v1/plataforma/usuarios/{$soporte->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertStatus(422)->assertJsonPath('error.code', 'RESTABLECIMIENTO_PROPIO');
    $api()->postJson("/api/v1/plataforma/usuarios/{$this->user->id}/doble-factor/restablecer", [])->assertStatus(422);

    $cobranza = User::factory()->dePlataforma(Rol::Cobranza)->create();
    ($this->conSesion)($cobranza)->postJson("/api/v1/plataforma/usuarios/{$this->user->id}/doble-factor/restablecer", ['motivo' => 'Quiero'])->assertForbidden();
});

it('una refresh de sesión no vuelve a pedir el código (la sesión ya pasó el segundo paso)', function () {
    activarDobleFactor($this->user);
    $desafio = ($this->iniciar)()->json('data.desafio');
    $secreto = $this->user->fresh()->two_factor_secret;
    $r = test()->postJson('/api/v1/auth/2fa/verificar', ['desafio' => $desafio, 'codigo' => Totp::codigo($secreto, (int) $this->user->fresh()->two_factor_last_step + 1)])->assertOk();

    $galleta = $r->getCookie('safic_refresh', false);
    test()->withCredentials()->withUnencryptedCookie('safic_refresh', $galleta->getValue())->postJson('/api/v1/auth/refresh')->assertOk()->assertJsonStructure(['data' => ['access_token']]);
});
