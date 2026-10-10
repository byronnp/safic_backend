<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Finanzas\Services\CierreMes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consulta: cómo está el cierre de un mes (lista de verificación, resumen y quién lo cerró).
 */
final class ObtenerCierreMesAction
{
    public function __construct(private readonly CierreMes $cierre) {}

    /**
     * @param  string  $periodo  YYYY-MM
     * @return array<string, mixed>
     */
    public function execute(string $periodo): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo) ?: throw new ApiException('PERIODO_INVALIDO', 'El mes no es válido.', 422);
        $registro = PeriodoFinanciero::query()->where('periodo', $mes->toDateString())->first();
        $verificaciones = $this->cierre->verificaciones($mes);

        $cerrado = $registro?->estado === PeriodoFinanciero::CERRADO;
        $bloqueos = array_values(array_filter($verificaciones, fn (array $v): bool => $v['estado'] === 'bloquea'));

        $anterior = PeriodoFinanciero::query()->where('periodo', '<', $mes->toDateString())->where('estado', PeriodoFinanciero::CERRADO)
            ->orderByDesc('periodo')->first();

        $nombres = DB::table('users')
            ->whereIn('id', array_filter([$registro?->cerrado_por, $registro?->reabierto_por, $anterior?->cerrado_por]))
            ->pluck('name', 'id');

        return [
            'periodo' => $periodo,
            'emitido' => $registro !== null,
            'estado' => $registro?->estado,
            'verificaciones' => $verificaciones,
            'puede_cerrar' => $registro !== null && ! $cerrado && $bloqueos === [],
            'motivo_bloqueo' => $bloqueos[0]['detalle'] ?? null,
            'resumen' => $cerrado && $registro->resumen_cierre !== null ? $registro->resumen_cierre : $this->cierre->resumen($mes),
            'cierre' => $cerrado ? [
                'cerrado_por' => $registro->cerrado_por !== null ? ($nombres[$registro->cerrado_por] ?? null) : null,
                'cerrado_en' => $registro->cerrado_en?->toIso8601String(),
            ] : null,
            'reapertura' => $registro?->reabierto_en !== null ? [
                'reabierto_por' => $registro->reabierto_por !== null ? ($nombres[$registro->reabierto_por] ?? null) : null,
                'reabierto_en' => $registro->reabierto_en->toIso8601String(),
                'motivo' => $registro->motivo_reapertura,
            ] : null,
            'anterior_cerrado' => $anterior === null ? null : [
                'periodo' => $anterior->periodo->format('Y-m'),
                'por' => $anterior->cerrado_por !== null ? ($nombres[$anterior->cerrado_por] ?? null) : null,
            ],
        ];
    }
}
