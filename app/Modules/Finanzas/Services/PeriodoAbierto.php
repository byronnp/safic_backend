<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use Carbon\CarbonImmutable;

/**
 * Un mes cerrado es de solo lectura: no se emiten cuotas, ni se registran o anulan pagos y facturas
 * con fecha en ese mes. Cada caso de uso que escribe llama a `exigir` con la fecha del movimiento.
 */
final class PeriodoAbierto
{
    /**
     * @param  string  $fecha  YYYY-MM-DD (la fecha del movimiento, no la de hoy)
     */
    public function exigir(string $fecha): void
    {
        $mes = CarbonImmutable::parse($fecha)->startOfMonth();

        if (PeriodoFinanciero::query()->where('periodo', $mes->toDateString())->where('estado', PeriodoFinanciero::CERRADO)->exists()) {
            throw new ApiException(
                'PERIODO_CERRADO',
                'El mes de '.self::nombre($mes).' está cerrado: no admite movimientos con fecha en ese mes. Pide al administrador que lo reabra.',
                409,
                ['periodo' => $mes->format('Y-m')],
            );
        }
    }

    public static function nombre(CarbonImmutable $mes): string
    {
        return $mes->locale('es')->translatedFormat('F \d\e Y');
    }
}
