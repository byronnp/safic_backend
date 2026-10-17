<?php

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Ocupante;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogosSeeder;
use Tests\TestCase;

// TestCase ya usa RefreshDatabase (ver su comentario).
pest()->extend(TestCase::class)->in('Feature');

/**
 * Crea un usuario miembro de $condominio con $rol y devuelve [usuario, access token].
 *
 * @return array{0: User, 1: string}
 */
function usuarioConToken(Condominio $condominio, ?Rol $rol = Rol::Administrador): array
{
    $user = User::factory()->miembroDe($condominio, $rol)->create();
    $token = auth('api')->tokenById($user->id);

    return [$user, $token];
}

/**
 * Ejecuta $callback dentro de un condominio (contexto + RLS).
 */
function enCondominio(Condominio $condominio, callable $callback): mixed
{
    return app(TenantContext::class)->run($condominio->id, $callback);
}

/**
 * Catálogos de plataforma (planes, amenidades, provincias/cantones/parroquias).
 * Solo en las pruebas que los usan: la división territorial tiene ~1.600 filas.
 */
function sembrarCatalogos(): void
{
    test()->seed(CatalogosSeeder::class);
}

/**
 * Para hacer la siguiente petición con otro token en la misma prueba: el guard y
 * el singleton de JWT guardan el usuario y el token de la petición anterior.
 */
function cambiarDeUsuario(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken();
}

/**
 * "Hoy" en la hora de Ecuador, que es la del condominio (Calendario). `now()` está en UTC y
 * entre las 19:00 y la medianoche ya es el día siguiente: las pruebas que comparan fechas
 * con lo que calcula la API deben usar esta.
 */
function hoyLocal(): CarbonImmutable
{
    return CarbonImmutable::now('America/Guayaquil')->startOfDay();
}

/** Un residente con cuenta, dueño de una unidad. Devuelve [user, token, unidad]. */
function residenteDe(Condominio $condominio, string $codigo, string $relacion = 'propietario', array $unidad = []): array
{
    return enCondominio($condominio, function () use ($condominio, $codigo, $relacion, $unidad) {
        $u = Unidad::factory()->create(['codigo' => $codigo, 'cuota_mensual' => '80.00'] + $unidad);
        $user = User::factory()->miembroDe($condominio, Rol::Residente)->create();
        $p = Persona::factory()->create();
        $p->forceFill(['user_id' => $user->id])->save();
        Ocupante::create(['unidad_id' => $u->id, 'persona_id' => $p->id, 'relacion' => $relacion, 'es_principal' => true, 'fecha_inicio' => hoyLocal()->subYear()->toDateString()]);

        return [$user, auth('api')->tokenById($user->id), $u];
    });
}

function cuotaDe(Condominio $condominio, Unidad $unidad, int $mesesAtras, string $monto = '80.00', string $pagado = '0.00'): Cuota
{
    return enCondominio($condominio, function () use ($unidad, $mesesAtras, $monto, $pagado) {
        $periodo = hoyLocal()->startOfMonth()->subMonths($mesesAtras);

        return Cuota::create([
            'unidad_id' => $unidad->id, 'periodo' => $periodo->toDateString(), 'concepto' => 'ordinaria', 'monto' => $monto,
            // La del mes en curso vence a fin de mes: así no depende del día en que se corran las pruebas
            'pagado' => $pagado, 'vence_el' => ($mesesAtras === 0 ? $periodo->endOfMonth() : $periodo->day(10))->toDateString(),
        ]);
    });
}
