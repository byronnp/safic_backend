<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Services\CierreMes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso de uso: cerrar un mes. Solo si la lista de verificación no tiene bloqueos. Guarda quién y
 * cuándo, y el resumen que quedó (lo emitido, lo cobrado y lo pagado a proveedores). Desde ahí el
 * mes es de solo lectura (Services/PeriodoAbierto).
 */
final class CerrarMesAction
{
    public function __construct(private readonly CierreMes $cierre) {}

    public function execute(User $user, string $periodo): PeriodoFinanciero
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);

        return DB::transaction(function () use ($user, $mes): PeriodoFinanciero {
            $registro = PeriodoFinanciero::query()->where('periodo', $mes->toDateString())->lockForUpdate()->first()
                ?? throw new ApiException('PERIODO_SIN_EMITIR', 'Este mes no tiene cuotas emitidas: no hay nada que cerrar.', 409);

            if ($registro->estado === PeriodoFinanciero::CERRADO) {
                throw new ApiException('PERIODO_YA_CERRADO', 'Este mes ya está cerrado.', 409);
            }

            $bloqueo = collect($this->cierre->verificaciones($mes))->firstWhere('estado', 'bloquea');
            if ($bloqueo !== null) {
                throw new ApiException('CIERRE_BLOQUEADO', 'No se puede cerrar todavía: '.mb_strtolower($bloqueo['titulo']).'. '.$bloqueo['detalle'], 409, ['punto' => $bloqueo['clave']]);
            }

            $registro->forceFill([
                'estado' => PeriodoFinanciero::CERRADO,
                'cerrado_por' => $user->id,
                'cerrado_en' => now(),
                'resumen_cierre' => $this->cierre->resumen($mes),
            ])->save();

            Log::info('finanzas.mes_cerrado', ['periodo' => $mes->format('Y-m'), 'user_id' => $user->id]);

            return $registro;
        });
    }
}
