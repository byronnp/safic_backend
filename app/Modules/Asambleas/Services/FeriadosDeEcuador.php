<?php

namespace App\Modules\Asambleas\Services;

use Carbon\CarbonImmutable;

/**
 * Feriados nacionales de Ecuador, con sus fechas oficiales. Sirven para contar los días hábiles de la convocatoria (8 días hábiles,
 * Art. 37 del Reglamento de la Ley de Propiedad Horizontal).
 *
 * Se usan las fechas del feriado, sin el traslado a lunes que a veces decreta el gobierno: ese traslado cambia cada año y debe
 * confirmarse con asesoría legal. Los feriados locales (fundación de la ciudad, etc.) y los traslados decretados se agregan en
 * `config('safic.feriados_extra')` ('YYYY-MM-DD' => nombre) y se quitan con `config('safic.feriados_quitar')` (lista de fechas).
 */
final class FeriadosDeEcuador
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    /**
     * @param  array<string, string>|null  $extra  Feriados que se suman ('YYYY-MM-DD' => nombre); null = los de la configuración
     * @param  list<string>|null  $quitar  Fechas que se sacan de la lista nacional; null = las de la configuración
     */
    public function __construct(private readonly ?array $extra = null, private readonly ?array $quitar = null) {}

    /** @return array<string, string> fecha (YYYY-MM-DD) → nombre */
    public function delAnio(int $anio): array
    {
        if (isset($this->cache[$anio])) {
            return $this->cache[$anio];
        }

        $pascua = $this->pascua($anio);
        $feriados = [
            "{$anio}-01-01" => 'Año Nuevo',
            $pascua->subDays(48)->toDateString() => 'Lunes de Carnaval',
            $pascua->subDays(47)->toDateString() => 'Martes de Carnaval',
            $pascua->subDays(2)->toDateString() => 'Viernes Santo',
            "{$anio}-05-01" => 'Día del Trabajo',
            "{$anio}-05-24" => 'Batalla de Pichincha',
            "{$anio}-08-10" => 'Primer Grito de Independencia',
            "{$anio}-10-09" => 'Independencia de Guayaquil',
            "{$anio}-11-02" => 'Día de los Difuntos',
            "{$anio}-11-03" => 'Independencia de Cuenca',
            "{$anio}-12-25" => 'Navidad',
        ];

        foreach ($this->extra ?? (array) config('safic.feriados_extra', []) as $fecha => $nombre) {
            if (str_starts_with((string) $fecha, (string) $anio)) {
                $feriados[(string) $fecha] = (string) $nombre;
            }
        }
        foreach ($this->quitar ?? (array) config('safic.feriados_quitar', []) as $fecha) {
            unset($feriados[(string) $fecha]);
        }

        ksort($feriados);

        return $this->cache[$anio] = $feriados;
    }

    public function nombreDe(string $fecha): ?string
    {
        return $this->delAnio((int) substr($fecha, 0, 4))[$fecha] ?? null;
    }

    /** Domingo de Pascua (algoritmo de Meeus/Jones/Butcher, calendario gregoriano). */
    private function pascua(int $anio): CarbonImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anio, $mes, $dia)->startOfDay();
    }
}
