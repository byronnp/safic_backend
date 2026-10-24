<?php

use App\Modules\Asambleas\Services\DiasHabiles;
use App\Modules\Asambleas\Services\FeriadosDeEcuador;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->feriados = new FeriadosDeEcuador([], []);
    $this->dias = new DiasHabiles($this->feriados);
});

it('los feriados fijos y los que dependen de la Pascua caen en su fecha', function () {
    $f2026 = $this->feriados->delAnio(2026);

    // Pascua 2026: 5 de abril → Carnaval 16 y 17 de febrero, Viernes Santo 3 de abril
    expect($f2026)->toMatchArray([
        '2026-01-01' => 'Año Nuevo', '2026-02-16' => 'Lunes de Carnaval', '2026-02-17' => 'Martes de Carnaval', '2026-04-03' => 'Viernes Santo',
        '2026-05-01' => 'Día del Trabajo', '2026-05-24' => 'Batalla de Pichincha', '2026-08-10' => 'Primer Grito de Independencia',
        '2026-10-09' => 'Independencia de Guayaquil', '2026-11-02' => 'Día de los Difuntos', '2026-11-03' => 'Independencia de Cuenca', '2026-12-25' => 'Navidad',
    ])->and($f2026)->toHaveCount(11);

    // Pascua 2027: 28 de marzo → Carnaval 8 y 9 de febrero, Viernes Santo 26 de marzo
    expect(array_keys($this->feriados->delAnio(2027)))->toContain('2027-02-08', '2027-02-09', '2027-03-26');
});

it('un día es hábil de lunes a viernes si no es feriado', function () {
    expect($this->dias->esHabil('2026-10-08'))->toBeTrue()      // jueves
        ->and($this->dias->esHabil('2026-10-09'))->toBeFalse()  // viernes feriado
        ->and($this->dias->esHabil('2026-10-10'))->toBeFalse()  // sábado
        ->and($this->dias->esHabil('2026-10-11'))->toBeFalse()  // domingo
        ->and($this->dias->esHabil('2026-10-12'))->toBeTrue();  // lunes
});

it('cuenta los días hábiles desde la publicación (incluida) hasta el día anterior a la asamblea', function () {
    // Publicar el martes 6 de oct para el sábado 17: 6, 7, 8, 12, 13, 14, 15 y 16 (el 9 es feriado)
    expect($this->dias->entre('2026-10-06', '2026-10-17'))->toBe(8)
        ->and($this->dias->entre('2026-10-07', '2026-10-17'))->toBe(7);
});

it('el último día para convocar descuenta feriados y fines de semana (mockup: asamblea del sáb 17 oct → mar 6 oct)', function () {
    expect($this->dias->ultimoDiaParaConvocar('2026-10-17'))->toBe('2026-10-06');
    // Sin feriados en medio: 8 días hábiles = 10 días corridos antes (asamblea el sábado 14 de nov → publicar el jueves 5, que cuenta él mismo)
    expect($this->dias->entre($this->dias->ultimoDiaParaConvocar('2026-11-14'), '2026-11-14'))->toBeGreaterThanOrEqual(8);
});

it('el último día para convocar es el más tardío que da 8 días hábiles', function () {
    foreach (['2026-10-17', '2026-11-14', '2027-03-20', '2026-12-26'] as $asamblea) {
        $ultimo = $this->dias->ultimoDiaParaConvocar($asamblea);
        $siguiente = CarbonImmutable::parse($ultimo)->addDay()->toDateString();

        expect($this->dias->entre($ultimo, $asamblea))->toBeGreaterThanOrEqual(8)
            ->and($this->dias->entre($siguiente, $asamblea))->toBeLessThan(8);
    }
});

it('lista los feriados que caen en el período, sin contar los de fin de semana', function () {
    expect($this->dias->feriadosEntre('2026-10-06', '2026-10-16'))->toBe(['2026-10-09' => 'Independencia de Guayaquil'])
        // 2 y 3 de noviembre de 2026 son lunes y martes
        ->and($this->dias->feriadosEntre('2026-10-28', '2026-11-05'))->toHaveCount(2);
});

it('los feriados locales y los traslados decretados se agregan y se quitan por configuración', function () {
    $dias = new DiasHabiles(new FeriadosDeEcuador(['2026-10-12' => 'Traslado decretado'], ['2026-10-09']));

    expect($dias->esHabil('2026-10-12'))->toBeFalse()->and($dias->esHabil('2026-10-09'))->toBeTrue();
});
