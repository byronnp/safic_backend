<?php

namespace App\Modules\Asambleas\Services;

use Carbon\CarbonImmutable;

/**
 * Días hábiles: de lunes a viernes y que no sean feriado nacional (ver FeriadosDeEcuador).
 *
 * La convocatoria exige al menos 8 días hábiles de anticipación (Art. 37). Se cuentan desde el día de la publicación (incluido)
 * hasta el día anterior a la asamblea: publicar el martes 6 de octubre para una asamblea el sábado 17 da 8 días hábiles
 * (6, 7, 8, 12, 13, 14, 15 y 16; el 9 es feriado).
 */
final class DiasHabiles
{
    /** Días hábiles de anticipación que exige la ley para convocar. */
    public const ANTICIPACION_MINIMA = 8;

    public function __construct(private readonly FeriadosDeEcuador $feriados) {}

    public function esHabil(string $fecha): bool
    {
        return ! CarbonImmutable::parse($fecha)->isWeekend() && $this->feriados->nombreDe($fecha) === null;
    }

    /** Días hábiles desde `$desde` (incluido) hasta `$hasta` (excluido). */
    public function entre(string $desde, string $hasta): int
    {
        $cuenta = 0;
        for ($d = CarbonImmutable::parse($desde); $d->lt(CarbonImmutable::parse($hasta)); $d = $d->addDay()) {
            $cuenta += $this->esHabil($d->toDateString()) ? 1 : 0;
        }

        return $cuenta;
    }

    /** El último día en que se puede publicar la convocatoria para una asamblea en `$fechaAsamblea` (YYYY-MM-DD). */
    public function ultimoDiaParaConvocar(string $fechaAsamblea, int $minimo = self::ANTICIPACION_MINIMA): string
    {
        $cuenta = 0;
        $d = CarbonImmutable::parse($fechaAsamblea)->subDay();
        while (true) {
            $cuenta += $this->esHabil($d->toDateString()) ? 1 : 0;
            if ($cuenta >= $minimo) {
                return $d->toDateString();
            }
            $d = $d->subDay();
        }
    }

    /**
     * Feriados que caen entre dos fechas (ambas incluidas), para explicar por qué la anticipación es mayor.
     *
     * @return array<string, string> fecha → nombre
     */
    public function feriadosEntre(string $desde, string $hasta): array
    {
        $resultado = [];
        for ($d = CarbonImmutable::parse($desde); $d->lte(CarbonImmutable::parse($hasta)); $d = $d->addDay()) {
            $nombre = $this->feriados->nombreDe($d->toDateString());
            if ($nombre !== null && ! $d->isWeekend()) {
                $resultado[$d->toDateString()] = $nombre;
            }
        }

        return $resultado;
    }
}
