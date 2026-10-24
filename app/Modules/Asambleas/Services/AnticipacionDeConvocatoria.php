<?php

namespace App\Modules\Asambleas\Services;

use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ¿Se llega a tiempo para convocar? La ley exige al menos 8 días hábiles de anticipación (Art. 37). Dice cuál es el último día
 * para publicar la convocatoria, cuántos días hábiles hay hoy y qué feriados se descuentan, y a qué hora es la segunda
 * convocatoria (60 minutos después de la primera, Art. 39).
 */
final class AnticipacionDeConvocatoria
{
    public function __construct(private readonly DiasHabiles $dias, private readonly Calendario $calendario) {}

    /**
     * @return array{cumple: bool, minimo: int, dias_habiles: int, ultimo_dia: string, feriados: list<array{fecha: string, nombre: string}>, segunda_convocatoria: string}
     */
    public function de(CarbonInterface $fechaHora, ?string $hoy = null): array
    {
        $local = CarbonImmutable::instance($fechaHora)->setTimezone($this->calendario->zonaHoraria());
        $dia = $local->toDateString();
        $hoy ??= $this->calendario->hoy();
        $ultimo = $this->dias->ultimoDiaParaConvocar($dia);

        $feriados = [];
        foreach ($this->dias->feriadosEntre(min($hoy, $ultimo), $dia) as $fecha => $nombre) {
            $feriados[] = ['fecha' => $fecha, 'nombre' => $nombre];
        }

        return [
            'cumple' => $hoy <= $ultimo,
            'minimo' => DiasHabiles::ANTICIPACION_MINIMA,
            'dias_habiles' => $dia > $hoy ? $this->dias->entre($hoy, $dia) : 0,
            'ultimo_dia' => $ultimo,
            'feriados' => $feriados,
            'segunda_convocatoria' => $local->addMinutes(Asamblea::MINUTOS_SEGUNDA_CONVOCATORIA)->format('H:i'),
        ];
    }
}
