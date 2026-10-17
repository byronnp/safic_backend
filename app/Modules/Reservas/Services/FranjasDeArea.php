<?php

namespace App\Modules\Reservas\Services;

use App\Modules\Reservas\Models\ReglaArea;
use Carbon\CarbonImmutable;

/**
 * Cómo se parte el día de un área: franjas seguidas de la duración mínima, desde que abre hasta que cierra
 * (la última franja que no cabe completa se descarta). Una reserva ocupa una o más franjas seguidas, sin pasar
 * de la duración máxima. Aquí no hay base de datos: solo la regla.
 */
final class FranjasDeArea
{
    /** @return list<array{inicio: string, fin: string}> */
    public function delDia(ReglaArea $r): array
    {
        $franjas = [];
        $paso = $r->duracion_min_horas * 60;
        $cierra = $this->minutos($r->cierra);

        for ($m = $this->minutos($r->abre); $m + $paso <= $cierra; $m += $paso) {
            $franjas[] = ['inicio' => $this->hora($m), 'fin' => $this->hora($m + $paso)];
        }

        return $franjas;
    }

    /** Si la hora de inicio cae en una franja y las horas son un múltiplo de la mínima dentro del máximo. */
    public function valida(ReglaArea $r, string $inicio, int $horas): bool
    {
        if ($horas < $r->duracion_min_horas || $horas > $r->duracion_max_horas || $horas % $r->duracion_min_horas !== 0) {
            return false;
        }
        $m = $this->minutos($inicio);
        $abre = $this->minutos($r->abre);
        $paso = $r->duracion_min_horas * 60;

        return $m >= $abre && ($m - $abre) % $paso === 0 && $m + $horas * 60 <= $this->minutos($r->cierra);
    }

    public function fin(string $inicio, int $horas): string
    {
        return $this->hora($this->minutos($inicio) + $horas * 60);
    }

    /** 1 = lunes … 7 = domingo. */
    public function diaDeSemana(string $fecha): int
    {
        return CarbonImmutable::parse($fecha)->isoWeekday();
    }

    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    private function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
