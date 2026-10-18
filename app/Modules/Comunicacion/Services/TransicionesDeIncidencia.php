<?php

namespace App\Modules\Comunicacion\Services;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Comunicacion\Models\Incidencia;

/**
 * Los cambios de estado que se permiten y lo que dejan registrado. La primera acción de la administración (comentar o cambiar el estado)
 * marca el tiempo de respuesta.
 */
final class TransicionesDeIncidencia
{
    /** @var array<string, list<string>> */
    private const PERMITIDAS = [
        Incidencia::ABIERTO => [Incidencia::EN_PROCESO, Incidencia::RESUELTO],
        Incidencia::EN_PROCESO => [Incidencia::RESUELTO],
        Incidencia::RESUELTO => [Incidencia::EN_PROCESO, Incidencia::CERRADO],
        Incidencia::CERRADO => [],
    ];

    public function cambiar(Incidencia $i, string $nuevo, string $cierre = 'administracion'): void
    {
        if (! in_array($nuevo, self::PERMITIDAS[$i->estado] ?? [], true)) {
            throw new ApiException('TRANSICION_NO_PERMITIDA', 'No se puede pasar la incidencia de «'.$this->etiqueta($i->estado).'» a «'.$this->etiqueta($nuevo).'».', 409);
        }

        $i->estado = $nuevo;
        if ($nuevo === Incidencia::RESUELTO) {
            $i->resuelta_en = now();
        }
        if ($nuevo === Incidencia::CERRADO) {
            $i->cerrada_en = now();
            $i->cierre = $cierre;
        }
        if ($nuevo === Incidencia::EN_PROCESO) {
            // Reabierta (o tomada): deja de contar como resuelta
            $i->resuelta_en = null;
        }
        $i->save();
    }

    /** Marca la primera respuesta de la administración, una sola vez. */
    public function marcarRespuesta(Incidencia $i): void
    {
        if ($i->primera_respuesta_en === null) {
            $i->forceFill(['primera_respuesta_en' => now()])->save();
        }
    }

    public function etiqueta(string $estado): string
    {
        return match ($estado) {
            Incidencia::ABIERTO => 'Abierto',
            Incidencia::EN_PROCESO => 'En proceso',
            Incidencia::RESUELTO => 'Resuelto',
            default => 'Cerrado',
        };
    }
}
