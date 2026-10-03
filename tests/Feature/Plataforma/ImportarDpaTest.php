<?php

use App\Modules\Plataforma\Models\Canton;
use App\Modules\Plataforma\Models\Parroquia;
use Database\Seeders\CatalogosSeeder;

function csvDpa(string $contenido): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'dpa');
    file_put_contents($ruta, $contenido);

    return $ruta;
}

beforeEach(fn () => $this->seed(CatalogosSeeder::class));

it('importa cantones y parroquias del archivo del INEC', function () {
    $ruta = csvDpa("\u{FEFF}DPA_PROVIN;DPA_DESPRO;DPA_CANTON;DPA_DESCAN;DPA_PARROQ;DPA_DESPAR\n"
        ."17;PICHINCHA;1701;QUITO;170155;CONOCOTO\n"
        ."17;PICHINCHA;1701;QUITO;170157;CUMBAYA\n"
        ."23;SANTO DOMINGO DE LOS TSACHILAS;2301;SANTO DOMINGO;230150;SANTO DOMINGO DE LOS COLORADOS\n"
        ."90;ZONAS NO DELIMITADAS;9001;LAS GOLONDRINAS;900151;LAS GOLONDRINAS\n");

    $this->artisan('safic:importar-dpa', ['archivo' => $ruta])->assertSuccessful();

    expect(Canton::query()->count())->toBe(2)
        ->and(Parroquia::query()->count())->toBe(3)
        ->and(Parroquia::query()->where('codigo', '230150')->value('nombre'))->toBe('Santo Domingo de los Colorados')
        ->and(Canton::query()->where('codigo', '1701')->value('nombre'))->toBe('Quito');
});

it('se puede correr de nuevo sin duplicar', function () {
    $ruta = csvDpa("dpa_provin,dpa_despro,dpa_canton,dpa_descan,dpa_parroq,dpa_despar\n17,PICHINCHA,1701,QUITO,170155,CONOCOTO\n");

    $this->artisan('safic:importar-dpa', ['archivo' => $ruta])->assertSuccessful();
    $this->artisan('safic:importar-dpa', ['archivo' => $ruta])->assertSuccessful();

    expect(Parroquia::query()->count())->toBe(1);
});

it('rechaza un archivo sin las columnas del INEC', function () {
    $ruta = csvDpa("provincia,canton\nPICHINCHA,QUITO\n");

    $this->artisan('safic:importar-dpa', ['archivo' => $ruta])->assertFailed();
});
