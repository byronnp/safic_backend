<?php

namespace App\Modules\Garita\Services;

use App\Modules\Garita\Models\AutorizacionVisita;
use Carbon\CarbonImmutable;

/**
 * Decide si una autorización de visita sirve en este momento. Devuelve el motivo del rechazo, o null si es válida.
 * Sin base de datos: solo la regla (fecha, día de la semana, horario, uso). `$ahora` va en la hora local del condominio.
 */
final class ValidadorDeVisita
{
    public const REVOCADA = 'QR_REVOCADO';

    public const VENCIDA = 'QR_VENCIDO';

    public const YA_USADA = 'QR_YA_USADO';

    public const AUN_NO = 'QR_AUN_NO_VIGENTE';

    public const DIA = 'QR_DIA_NO_HABILITADO';

    public const HORARIO = 'QR_FUERA_DE_HORARIO';

    public function motivo(AutorizacionVisita $a, CarbonImmutable $ahora): ?string
    {
        $hoy = $ahora->toDateString();

        return match (true) {
            $a->revocada_en !== null => self::REVOCADA,
            $a->tipo === AutorizacionVisita::UNICA && $a->usos >= 1 => self::YA_USADA,
            $a->fecha_hasta->toDateString() < $hoy => self::VENCIDA,
            $a->fecha_desde->toDateString() > $hoy => self::AUN_NO,
            $a->tipo === AutorizacionVisita::RECURRENTE && ! in_array($ahora->isoWeekday(), $a->dias_semana ?? [], true) => self::DIA,
            $ahora->format('H:i') < substr($a->hora_desde, 0, 5) || $ahora->format('H:i') > substr($a->hora_hasta, 0, 5) => self::HORARIO,
            default => null,
        };
    }

    /** Mensaje para el guardia. */
    public function mensaje(string $motivo): string
    {
        return match ($motivo) {
            self::REVOCADA => 'El residente revocó esta autorización.',
            self::YA_USADA => 'Este QR de un solo ingreso ya se usó.',
            self::VENCIDA => 'Este QR ya venció.',
            self::AUN_NO => 'Este QR todavía no es válido: empieza otro día.',
            self::DIA => 'Este QR no vale para el día de hoy.',
            self::HORARIO => 'Este QR no vale a esta hora.',
            default => 'QR no válido.',
        };
    }
}
