<?php

use App\Core\Storage\ArchivoAjenoException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\Exceptions\CondominioNoResueltoException;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['filesystems.disco_archivos' => 'local']);
    Storage::fake('local');
});

it('guarda el archivo bajo el prefijo del condominio activo con nombre aleatorio', function () {
    $condominio = Condominio::factory()->create();

    $ruta = enCondominio($condominio, fn () => app(ArchivosCondominio::class)
        ->guardar(UploadedFile::fake()->image('cedula-juan.png'), 'comprobantes'));

    expect($ruta)->toStartWith("condominios/{$condominio->id}/comprobantes/")
        ->and($ruta)->not->toContain('cedula-juan')
        ->and($ruta)->toEndWith('.png');
    Storage::disk('local')->assertExists($ruta);
});

it('no entrega ni borra archivos de otro condominio', function () {
    $a = Condominio::factory()->create();
    $b = Condominio::factory()->create();

    $rutaDeA = enCondominio($a, fn () => app(ArchivosCondominio::class)
        ->guardar(UploadedFile::fake()->create('acta.pdf', 10, 'application/pdf'), 'actas'));

    enCondominio($b, function () use ($rutaDeA) {
        $archivos = app(ArchivosCondominio::class);

        expect($archivos->existe($rutaDeA))->toBeFalse();
        expect(fn () => $archivos->urlTemporal($rutaDeA))->toThrow(ArchivoAjenoException::class);
        expect(fn () => $archivos->eliminar($rutaDeA))->toThrow(ArchivoAjenoException::class);
    });

    Storage::disk('local')->assertExists($rutaDeA);
});

it('rechaza rutas con ".." que salen del prefijo', function () {
    $condominio = Condominio::factory()->create();

    enCondominio($condominio, function () use ($condominio) {
        $archivos = app(ArchivosCondominio::class);

        expect($archivos->perteneceAlCondominio("condominios/{$condominio->id}/../9/x.pdf"))->toBeFalse();
    });
});

it('rechaza carpetas inválidas', function () {
    $condominio = Condominio::factory()->create();

    enCondominio($condominio, function () {
        $archivo = UploadedFile::fake()->image('a.png');

        expect(fn () => app(ArchivosCondominio::class)->guardar($archivo, '../otro'))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('no guarda nada sin condominio activo', function () {
    expect(fn () => app(ArchivosCondominio::class)->guardar(UploadedFile::fake()->image('a.png'), 'fotos'))
        ->toThrow(CondominioNoResueltoException::class);
});
