<?php

use App\Core\Audit\Auditoria;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Persona;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->a = Condominio::factory()->create();
    $this->b = Condominio::factory()->create();
});

it('registra quién cambió una unidad, con valores anteriores y nuevos', function () {
    [$usuario] = usuarioConToken($this->a);
    auth('api')->setUser($usuario);

    enCondominio($this->a, function () {
        $unidad = Unidad::factory()->create(['codigo' => 'A-101']);
        $unidad->update(['codigo' => 'A-102']);
    });

    $cambio = enCondominio($this->a, fn () => Auditoria::where('event', 'updated')->sole());

    expect($cambio->condominio_id)->toBe($this->a->id)
        ->and($cambio->user_id)->toBe($usuario->id)
        ->and($cambio->old_values)->toMatchArray(['codigo' => 'A-101'])
        ->and($cambio->new_values)->toMatchArray(['codigo' => 'A-102']);
});

it('no guarda los datos personales de una persona', function () {
    enCondominio($this->a, fn () => Persona::factory()->create([
        'documento' => 'P1234567', 'telefono' => '0999999999', 'email' => 'ana@example.com',
    ]));

    $alta = enCondominio($this->a, fn () => Auditoria::where('event', 'created')->sole());
    $guardado = json_encode($alta->new_values);

    expect($guardado)->not->toContain('P1234567')
        ->not->toContain('0999999999')
        ->not->toContain('ana@example.com')
        ->not->toContain('documento_hash');
});

it('un condominio no ve la auditoría de otro', function () {
    enCondominio($this->a, fn () => Unidad::factory()->create());
    enCondominio($this->b, fn () => Unidad::factory()->create());

    expect(enCondominio($this->a, fn () => Auditoria::count()))->toBe(1)
        ->and(enCondominio($this->b, fn () => Auditoria::count()))->toBe(1)
        ->and(enCondominio($this->b, fn () => Auditoria::first()->condominio_id))->toBe($this->b->id);
});

it('la aplicación no puede modificar ni borrar la auditoría', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Los permisos por rol son de PostgreSQL.');
    }

    enCondominio($this->a, function () {
        Unidad::factory()->create();

        // Un punto de guardado por intento: el error de permiso no aborta la transacción del condominio.
        expect(fn () => DB::transaction(fn () => DB::table('audits')->update(['event' => 'borrado'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('audits')->delete()))->toThrow(QueryException::class);
    });
});
