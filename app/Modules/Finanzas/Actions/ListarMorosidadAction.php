<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\RecordatorioMora;
use App\Modules\Finanzas\Models\RestriccionMora;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;
use App\Modules\Unidades\Actions\UnidadesACobrarAction;

/**
 * Consulta: las unidades con cuotas vencidas, de la que más atraso tiene a la que menos, con su
 * situación (restringida, notificada, con convenio, con recordatorio) y la cartera por antigüedad.
 */
final class ListarMorosidadAction
{
    public const FILTROS = ['todas', 'en_mora', 'restringidas', 'convenio'];

    private const TRAMOS = [['d1_30', '1–30 días', 1, 30], ['d31_60', '31–60 días', 31, 60], ['d61_90', '61–90 días', 61, 90], ['d90_mas', 'Más de 90', 91, PHP_INT_MAX]];

    public function __construct(
        private readonly MorosidadService $morosidad,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ResponsablesDePagoAction $responsables,
        private readonly UnidadesACobrarAction $aCobrar,
    ) {}

    /**
     * @return array{unidades: list<array<string, mixed>>, total: int, resumen: array<string, mixed>, conteos: array<string, int>, reglas: array<string, int>}
     */
    public function execute(string $filtro, string $buscar, int $pagina, int $porPagina): array
    {
        $deudas = $this->morosidad->deudas();
        $ids = array_keys($deudas);

        $codigos = $this->codigos->execute($ids);
        $responsables = $this->responsables->execute($ids);
        $convenios = $this->morosidad->convenios($ids);
        $restricciones = RestriccionMora::query()->whereIn('estado', [RestriccionMora::NOTIFICADA, RestriccionMora::ACTIVA])->get()->keyBy('unidad_id');
        $ultimos = RecordatorioMora::query()->whereIn('unidad_id', $ids)->selectRaw('unidad_id, max(created_at) as ultimo')->groupBy('unidad_id')->pluck('ultimo', 'unidad_id');

        $filas = [];
        foreach ($deudas as $id => $d) {
            $convenio = $convenios[$id] ?? null;
            $restriccion = $restricciones->get($id);
            $filas[] = [
                'unidad_id' => $id,
                'codigo' => $codigos[$id] ?? '—',
                'responsable' => $responsables[$id] ?? null,
                'cuotas_vencidas' => $d['cuotas'],
                'meses_vencidos' => $d['meses'],
                'dias_atraso' => $d['dias'],
                'saldo_vencido' => Dinero::texto($d['saldo']),
                'en_mora' => $this->morosidad->enMora($d, $convenio),
                'situacion' => match (true) {
                    $restriccion?->estado === RestriccionMora::ACTIVA => ['tipo' => 'restringida', 'desde' => $restriccion->restringe_desde->toDateString()],
                    $restriccion !== null => ['tipo' => 'notificada', 'restringe_desde' => $restriccion->restringe_desde->toDateString()],
                    $convenio !== null => ['tipo' => 'convenio', 'cuota' => min($convenio['cubiertas'] + 1, $convenio['convenio']->cuotas), 'de' => $convenio['convenio']->cuotas, 'al_dia' => $convenio['al_dia']],
                    $ultimos->has($id) => ['tipo' => 'recordatorio', 'fecha' => substr((string) $ultimos[$id], 0, 10)],
                    default => ['tipo' => 'ninguna'],
                },
            ];
        }
        usort($filas, fn (array $a, array $b) => [$b['dias_atraso'], $a['codigo']] <=> [$a['dias_atraso'], $b['codigo']]);

        $conteos = [
            'todas' => count($filas),
            'en_mora' => count(array_filter($filas, fn (array $f) => $f['en_mora'])),
            'restringidas' => count(array_filter($filas, fn (array $f) => in_array($f['situacion']['tipo'], ['restringida', 'notificada'], true))),
            'convenio' => count(array_filter($filas, fn (array $f) => $f['situacion']['tipo'] === 'convenio')),
        ];

        $visibles = array_values(array_filter($filas, function (array $f) use ($filtro, $buscar): bool {
            $pasa = match ($filtro) {
                'en_mora' => $f['en_mora'],
                'restringidas' => in_array($f['situacion']['tipo'], ['restringida', 'notificada'], true),
                'convenio' => $f['situacion']['tipo'] === 'convenio',
                default => true,
            };

            return $pasa && ($buscar === '' || str_contains(mb_strtolower($f['codigo'].' '.($f['responsable']['nombre'] ?? '')), mb_strtolower($buscar)));
        }));

        return [
            'unidades' => array_slice($visibles, ($pagina - 1) * $porPagina, $porPagina),
            'total' => count($visibles),
            'resumen' => $this->resumen($deudas),
            'conteos' => $conteos,
            'reglas' => $this->morosidad->reglas(),
        ];
    }

    /**
     * @param  array<int, array{unidad_id: int, cuotas: int, meses: int, dias: int, saldo: int, primera: string}>  $deudas
     * @return array<string, mixed>
     */
    private function resumen(array $deudas): array
    {
        $totalUnidades = count($this->aCobrar->execute()['unidades']);
        $tramos = [['tramo' => 'al_dia', 'etiqueta' => 'Al día', 'unidades' => max(0, $totalUnidades - count($deudas)), 'saldo' => '0.00']];

        foreach (self::TRAMOS as [$clave, $etiqueta, $desde, $hasta]) {
            $enTramo = array_filter($deudas, fn (array $d) => $d['dias'] >= $desde && $d['dias'] <= $hasta);
            $tramos[] = ['tramo' => $clave, 'etiqueta' => $etiqueta, 'unidades' => count($enTramo), 'saldo' => Dinero::texto(array_sum(array_column($enTramo, 'saldo')))];
        }

        return [
            'cartera_vencida' => ['saldo' => Dinero::texto(array_sum(array_column($deudas, 'saldo'))), 'unidades' => count($deudas)],
            'total_unidades' => $totalUnidades,
            'tramos' => $tramos,
        ];
    }
}
