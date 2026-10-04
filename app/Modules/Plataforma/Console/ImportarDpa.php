<?php

namespace App\Modules\Plataforma\Console;

use App\Modules\Plataforma\Models\Canton;
use App\Modules\Plataforma\Models\Parroquia;
use App\Modules\Plataforma\Models\Provincia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa cantones y parroquias desde la codificación de la División
 * Político-Administrativa (DPA) del INEC, guardada como CSV.
 *
 * Columnas (los nombres que usa el INEC; mayúsculas o minúsculas, separador , o ;):
 *   DPA_PROVIN, DPA_DESPRO, DPA_CANTON, DPA_DESCAN, DPA_PARROQ, DPA_DESPAR
 *
 * Idempotente: actualiza por código y no borra nada.
 */
class ImportarDpa extends Command
{
    protected $signature = 'safic:importar-dpa {archivo : Ruta del CSV de la DPA del INEC}';

    protected $description = 'Importa cantones y parroquias del Ecuador (DPA del INEC)';

    private const COLUMNAS = ['dpa_provin', 'dpa_despro', 'dpa_canton', 'dpa_descan', 'dpa_parroq', 'dpa_despar'];

    public function handle(): int
    {
        $ruta = (string) $this->argument('archivo');
        $archivo = is_readable($ruta) ? fopen($ruta, 'r') : false;

        if ($archivo === false) {
            $this->error("No se puede leer el archivo: {$ruta}");

            return self::FAILURE;
        }

        $primera = (string) fgets($archivo);
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        $encabezado = array_map(fn ($c) => mb_strtolower(trim((string) $c, " \t\n\r\0\x0B\u{FEFF}\"")), str_getcsv($primera, $separador, '"', ''));
        $faltan = array_diff(self::COLUMNAS, $encabezado);

        if ($faltan !== []) {
            $this->error('Faltan columnas: '.implode(', ', $faltan));
            fclose($archivo);

            return self::FAILURE;
        }

        $indice = array_flip($encabezado);
        $cuenta = ['provincias' => 0, 'cantones' => 0, 'parroquias' => 0, 'omitidas' => 0];

        DB::transaction(function () use ($archivo, $separador, $indice, &$cuenta) {
            $provincias = [];
            $cantones = [];

            while (($fila = fgetcsv($archivo, 0, $separador, '"', '')) !== false) {
                $codProvincia = str_pad(trim((string) ($fila[$indice['dpa_provin']] ?? '')), 2, '0', STR_PAD_LEFT);
                $codCanton = str_pad(trim((string) ($fila[$indice['dpa_canton']] ?? '')), 4, '0', STR_PAD_LEFT);
                $codParroquia = str_pad(trim((string) ($fila[$indice['dpa_parroq']] ?? '')), 6, '0', STR_PAD_LEFT);

                // Solo las 24 provincias (el INEC usa 90 para zonas no delimitadas).
                if (! preg_match('/^(0[1-9]|1\d|2[0-4])$/', $codProvincia)
                    || ! str_starts_with($codCanton, $codProvincia)
                    || ! str_starts_with($codParroquia, $codCanton)) {
                    $cuenta['omitidas']++;

                    continue;
                }

                if (! isset($provincias[$codProvincia])) {
                    $provincias[$codProvincia] = Provincia::query()->firstOrCreate(
                        ['codigo' => $codProvincia],
                        ['nombre' => self::nombre((string) $fila[$indice['dpa_despro']])],
                    )->id;
                    $cuenta['provincias']++;
                }

                if (! isset($cantones[$codCanton])) {
                    $cantones[$codCanton] = Canton::query()->updateOrCreate(
                        ['codigo' => $codCanton],
                        ['provincia_id' => $provincias[$codProvincia], 'nombre' => self::nombre((string) $fila[$indice['dpa_descan']])],
                    )->id;
                    $cuenta['cantones']++;
                }

                Parroquia::query()->updateOrCreate(
                    ['codigo' => $codParroquia],
                    ['canton_id' => $cantones[$codCanton], 'nombre' => self::nombre((string) $fila[$indice['dpa_despar']])],
                );
                $cuenta['parroquias']++;
            }
        });

        fclose($archivo);
        $this->info("Provincias: {$cuenta['provincias']} · cantones: {$cuenta['cantones']} · parroquias: {$cuenta['parroquias']} · filas omitidas: {$cuenta['omitidas']}");

        return self::SUCCESS;
    }

    /**
     * "SANTO DOMINGO DE LOS TSACHILAS" → "Santo Domingo de los Tsachilas".
     * El INEC publica en mayúsculas; las tildes que traiga el archivo se respetan.
     */
    public static function nombre(string $valor): string
    {
        $titulo = mb_convert_case(mb_strtolower(trim($valor)), MB_CASE_TITLE, 'UTF-8');
        $titulo = (string) preg_replace('/\s+/u', ' ', $titulo);

        return (string) preg_replace_callback(
            '/(?<=\s)(De|Del|La|Las|Los|El|Y|E)(?=\s)/u',
            fn (array $m) => mb_strtolower($m[1]),
            $titulo,
        );
    }
}
