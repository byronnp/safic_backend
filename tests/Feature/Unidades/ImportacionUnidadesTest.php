<?php

use App\Core\Permissions\Rol;
use App\Modules\Finanzas\Actions\GuardarConfiguracionCobroAction;
use App\Modules\Plataforma\Models\Condominio;
use App\Modules\Unidades\Models\Bloque;
use App\Modules\Unidades\Models\Unidad;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Crea un .xlsx con $filas (la primera son los títulos) y lo devuelve como archivo subido.
 *
 * @param  list<list<mixed>>  $filas
 */
function excelDeUnidades(array $filas, string $nombre = 'unidades.xlsx'): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'imp-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($ruta);
    foreach ($filas as $fila) {
        $writer->addRow(Row::fromValues($fila));
    }
    $writer->close();

    return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function cobroDeImportacion(Condominio $condominio, string $metodo): void
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
    $this->condominio = Condominio::factory()->create(['total_unidades' => 10]);
    cobroDeImportacion($this->condominio, 'alicuota');
    [, $this->token] = usuarioConToken($this->condominio);
    $this->api = fn () => $this->withToken($this->token)->withHeader('X-Condominio-Id', (string) $this->condominio->id);
    $this->importar = fn (UploadedFile $archivo, bool $confirmar = false) => ($this->api)()->post(
        '/api/v1/unidades/importacion',
        ['archivo' => $archivo] + ($confirmar ? ['confirmar' => 1] : []),
        ['Accept' => 'application/json'],
    );
    $this->titulos = ['codigo', 'bloque', 'tipo', 'piso', 'area_m2', 'alicuota'];
});

it('la vista previa valida sin crear nada', function () {
    $archivo = excelDeUnidades([
        $this->titulos,
        ['a-101', 'Torre A', 'departamento', 1, 84.5, 1.25],
        ['A-102', 'Torre A', 'departamento', 1, 84.5, 1.25],
        ['P-01', 'Torre B', 'parqueadero', -1, 12, 0.1],
    ]);

    ($this->importar)($archivo)->assertOk()
        ->assertJsonPath('data.confirmado', false)
        ->assertJsonPath('data.total_filas', 3)
        ->assertJsonPath('data.validas', 3)
        ->assertJsonPath('data.con_errores', 0)
        ->assertJsonPath('data.bloques_nuevos', ['Torre A', 'Torre B'])
        ->assertJsonPath('data.cupo.nuevas', 2)
        ->assertJsonPath('data.cupo.alcanza', true);

    expect(enCondominio($this->condominio, fn () => Unidad::count()))->toBe(0)
        ->and(enCondominio($this->condominio, fn () => Bloque::count()))->toBe(0);
});

it('al confirmar crea las unidades y los bloques que faltan', function () {
    enCondominio($this->condominio, fn () => Bloque::create(['nombre' => 'torre a', 'orden' => 1]));
    $archivo = excelDeUnidades([
        $this->titulos,
        ['a-101', 'Torre A', 'Departamento', 3.0, '84.50', 1.25],
        ['B-201', 'Torre B', 'casa', null, 120, 2],
    ]);

    ($this->importar)($archivo, true)->assertCreated()
        ->assertJsonPath('data.confirmado', true)
        ->assertJsonPath('data.creadas', 2);

    enCondominio($this->condominio, function () {
        $a = Unidad::where('codigo', 'A-101')->sole();
        expect($a->piso)->toBe(3)
            ->and($a->area_m2)->toBe('84.50')
            ->and($a->bloque->nombre)->toBe('torre a')   // reutiliza el bloque existente sin importar mayúsculas
            ->and(Bloque::count())->toBe(2)
            ->and(Unidad::where('codigo', 'B-201')->sole()->bloque->nombre)->toBe('Torre B');
    });
});

it('señala el error de cada fila y no crea nada si hay errores', function () {
    enCondominio($this->condominio, fn () => Unidad::factory()->create(['codigo' => 'A-101']));
    $archivo = excelDeUnidades([
        $this->titulos,
        ['A-101', null, 'departamento', 1, 80, 1],   // ya existe
        ['A-300', null, 'castillo', 1, 80, 1],       // tipo inválido
        ['A-301', null, 'casa', 1, 0, null],         // área y alícuota
        ['A-302', null, 'casa', 1, 80, 1],           // válida
        ['A-302', null, 'casa', 1, 80, 1],           // repetida en el archivo
    ]);

    $vista = ($this->importar)($archivo)->assertOk()
        ->assertJsonPath('data.validas', 1)
        ->assertJsonPath('data.con_errores', 4);

    $errores = collect($vista->json('data.errores'));
    expect($errores->where('fila', 2)->pluck('campo')->all())->toBe(['codigo'])
        ->and($errores->where('fila', 3)->pluck('campo')->all())->toBe(['tipo'])
        ->and($errores->where('fila', 4)->pluck('campo')->sort()->values()->all())->toBe(['alicuota', 'area_m2'])
        ->and($errores->where('fila', 6)->first()['mensaje'])->toContain('fila 5');

    ($this->importar)($archivo, true)->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_CON_ERRORES');
    expect(enCondominio($this->condominio, fn () => Unidad::count()))->toBe(1);
});

it('respeta el total contratado: no importa nada si no alcanza', function () {
    $this->condominio->update(['total_unidades' => 2]);
    $archivo = excelDeUnidades([
        $this->titulos,
        ['A-1', null, 'casa', 1, 80, 1],
        ['A-2', null, 'casa', 1, 80, 1],
        ['A-3', null, 'casa', 1, 80, 1],
        ['P-1', null, 'parqueadero', 1, 12, 0.1],   // no cuenta para el total
    ]);

    ($this->importar)($archivo)->assertOk()->assertJsonPath('data.cupo.alcanza', false);
    ($this->importar)($archivo, true)->assertStatus(409)->assertJsonPath('error.code', 'LIMITE_UNIDADES');
    expect(enCondominio($this->condominio, fn () => Unidad::count()))->toBe(0);
});

it('pide las columnas del método de cobro y rechaza las que no conoce', function () {
    ($this->importar)(excelDeUnidades([['codigo', 'tipo'], ['A-1', 'casa']]))
        ->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_COLUMNAS');

    ($this->importar)(excelDeUnidades([[...$this->titulos, 'color'], ['A-1', null, 'casa', 1, 80, 1, 'rojo']]))
        ->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_COLUMNAS');
});

it('rechaza archivos que no son Excel o que superan las 500 filas', function () {
    $texto = UploadedFile::fake()->createWithContent('unidades.xlsx', 'no soy un excel');
    ($this->importar)($texto)->assertStatus(422);

    ($this->importar)(UploadedFile::fake()->create('unidades.csv', 5, 'text/csv'))->assertStatus(422);

    $filas = [$this->titulos];
    foreach (range(1, 501) as $n) {
        $filas[] = ["U-$n", null, 'casa', 1, 80, 0.1];
    }
    ($this->importar)(excelDeUnidades($filas))->assertStatus(422)->assertJsonPath('error.code', 'IMPORTACION_MUY_GRANDE');
});

it('la plantilla trae las columnas del método de cobro', function () {
    $respuesta = ($this->api)()->get('/api/v1/unidades/importacion/plantilla')->assertOk();
    expect($respuesta->headers->get('content-disposition'))->toContain('plantilla-unidades.xlsx');

    // La plantilla se puede volver a importar tal cual (sin filas): queda vacía, no falla por columnas.
    $ruta = tempnam(sys_get_temp_dir(), 'pl-').'.xlsx';
    file_put_contents($ruta, $respuesta->streamedContent());
    $vista = ($this->importar)(new UploadedFile($ruta, 'plantilla.xlsx', null, null, true))->assertOk();
    expect($vista->json('data.total_filas'))->toBe(0);
});

it('solo quien edita unidades puede importar', function () {
    [, $token] = usuarioConToken($this->condominio, Rol::Guardia);
    $archivo = excelDeUnidades([$this->titulos]);

    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->post('/api/v1/unidades/importacion', ['archivo' => $archivo], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->withToken($token)->withHeader('X-Condominio-Id', (string) $this->condominio->id)
        ->getJson('/api/v1/unidades/importacion/plantilla')->assertForbidden();
});

it('no mezcla unidades de otro condominio', function () {
    $otro = Condominio::factory()->create(['total_unidades' => 10]);
    enCondominio($otro, fn () => Unidad::factory()->create(['codigo' => 'A-101']));

    ($this->importar)(excelDeUnidades([$this->titulos, ['A-101', null, 'casa', 1, 80, 1]]), true)->assertCreated();

    expect(enCondominio($this->condominio, fn () => Unidad::count()))->toBe(1)
        ->and(enCondominio($otro, fn () => Unidad::count()))->toBe(1);
});
