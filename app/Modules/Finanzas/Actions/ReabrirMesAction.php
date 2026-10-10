<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Models\User;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Caso de uso: reabrir un mes cerrado. Solo el último mes cerrado (reabrir uno anterior dejaría
 * meses posteriores cerrados sobre datos que cambian), con motivo, y queda auditado.
 */
final class ReabrirMesAction
{
    public function execute(User $user, string $periodo, string $motivo): PeriodoFinanciero
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);

        return DB::transaction(function () use ($user, $mes, $motivo): PeriodoFinanciero {
            $registro = PeriodoFinanciero::query()->where('periodo', $mes->toDateString())->lockForUpdate()->first();

            if ($registro === null || $registro->estado !== PeriodoFinanciero::CERRADO) {
                throw new ApiException('PERIODO_NO_CERRADO', 'Este mes no está cerrado.', 409);
            }

            $posterior = PeriodoFinanciero::query()->where('periodo', '>', $mes->toDateString())->where('estado', PeriodoFinanciero::CERRADO)->orderBy('periodo')->first();
            if ($posterior !== null) {
                throw new ApiException('REABRIR_EL_ULTIMO', 'Primero reabre el mes de '.$posterior->periodo->locale('es')->translatedFormat('F \d\e Y').': solo se reabre el último mes cerrado.', 409, ['periodo' => $posterior->periodo->format('Y-m')]);
            }

            $registro->forceFill([
                'estado' => PeriodoFinanciero::ABIERTO,
                'reabierto_por' => $user->id,
                'reabierto_en' => now(),
                'motivo_reapertura' => $motivo,
            ])->save();

            Log::warning('finanzas.mes_reabierto', ['periodo' => $mes->format('Y-m'), 'user_id' => $user->id, 'motivo' => $motivo]);

            return $registro;
        });
    }
}
