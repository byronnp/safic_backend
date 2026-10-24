<?php

namespace App\Modules\Asambleas\Services;

use App\Core\Tenancy\Calendario;
use App\Modules\Asambleas\Models\Asamblea;
use Carbon\CarbonImmutable;

/**
 * El texto de la convocatoria que se publica como anuncio: cuándo y dónde es, la segunda convocatoria y el orden del día
 * (Art. 37: por escrito, con lugar, fecha, hora y orden del día).
 */
final class MensajeDeConvocatoria
{
    public function __construct(private readonly Calendario $calendario) {}

    public function titulo(Asamblea $a): string
    {
        return mb_substr("Convocatoria: {$a->titulo}", 0, 120);
    }

    public function texto(Asamblea $a): string
    {
        $inicio = CarbonImmutable::instance($a->fecha_hora)->setTimezone($this->calendario->zonaHoraria())->locale('es');
        $segunda = $inicio->addMinutes(Asamblea::MINUTOS_SEGUNDA_CONVOCATORIA)->format('H:i');
        $tipo = $a->tipo === 'ordinaria' ? 'asamblea ordinaria' : 'asamblea extraordinaria';

        $donde = match ($a->modalidad) {
            'virtual' => "Será virtual. Enlace: {$a->enlace_video}",
            'mixta' => "Lugar: {$a->lugar}. También se puede participar por videollamada: {$a->enlace_video}",
            default => "Lugar: {$a->lugar}.",
        };

        $lineas = [
            "Se convoca a la {$tipo} de copropietarios para el ".$inicio->translatedFormat('l j \d\e F \d\e Y').' a las '.$inicio->format('H:i').' (primera convocatoria).',
            $donde,
            "Si no hay quórum, la asamblea se instalará en segunda convocatoria a las {$segunda} con los presentes.",
            '',
            'Orden del día:',
        ];
        foreach ($a->puntos as $p) {
            $lineas[] = "{$p->orden}. {$p->titulo}";
        }
        $lineas[] = '';
        $lineas[] = 'Confirma la recepción de esta convocatoria desde la app. Si no puedes asistir, tu representante puede hacerlo con un poder.';

        return implode("\n", $lineas);
    }
}
