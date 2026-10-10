<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Finanzas\Models\PeriodoFinanciero;
use App\Modules\Unidades\Actions\CodigosDeUnidadesAction;
use App\Modules\Unidades\Actions\ResponsablesDePagoAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de la administración (Cuotas del mes): lo emitido, cobrado y por aprobar del mes, lo
 * vencido de meses anteriores y una fila por unidad con su estado. Los estados no se cruzan:
 * pagada, por aprobar (hay un pago en revisión), vencida (pasó su fecha) o pendiente.
 */
final class ListarCuotasDelMesAction
{
    public const FILTROS = ['todas', 'pendiente', 'por_aprobar', 'vencida', 'pagada'];

    public function __construct(
        private readonly Calendario $calendario,
        private readonly CodigosDeUnidadesAction $codigos,
        private readonly ResponsablesDePagoAction $responsables,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $periodo, string $filtro, string $buscar, int $pagina, int $porPagina): array
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $periodo)->toDateString();
        $hoy = $this->calendario->hoy();

        $filas = DB::select(
            'select unidad_id, sum(monto)::numeric(14,2) as monto, sum(pagado)::numeric(14,2) as pagado,
                    min(vence_el) as vence_el
             from cuotas where periodo = ? group by unidad_id',
            [$mes],
        );

        $unidadIds = array_map(fn ($f) => (int) $f->unidad_id, $filas);
        $enRevision = $this->unidadesConPagoEnRevision($periodo);
        $codigos = $this->codigos->execute($unidadIds);
        $responsables = $this->responsables->execute($unidadIds);

        $unidades = [];
        foreach ($filas as $f) {
            $id = (int) $f->unidad_id;
            $saldo = Dinero::centavos((string) $f->monto) - Dinero::centavos((string) $f->pagado);
            $estado = match (true) {
                $saldo <= 0 => 'pagada',
                in_array($id, $enRevision, true) => 'por_aprobar',
                (string) $f->vence_el < $hoy => 'vencida',
                default => 'pendiente',
            };
            $unidades[] = [
                'unidad_id' => $id,
                'codigo' => $codigos[$id] ?? '—',
                'responsable' => $responsables[$id] ?? null,
                'cuota' => (string) $f->monto,
                'pagado' => (string) $f->pagado,
                'saldo' => Dinero::texto(max(0, $saldo)),
                'vence_el' => (string) $f->vence_el,
                'estado' => $estado,
            ];
        }
        usort($unidades, fn (array $a, array $b) => strnatcasecmp($a['codigo'], $b['codigo']));

        $conteos = array_fill_keys(self::FILTROS, 0);
        $conteos['todas'] = count($unidades);
        foreach ($unidades as $u) {
            $conteos[$u['estado']]++;
        }

        $visibles = array_values(array_filter($unidades, function (array $u) use ($filtro, $buscar): bool {
            if ($filtro !== 'todas' && $u['estado'] !== $filtro) {
                return false;
            }

            return $buscar === ''
                || str_contains(mb_strtolower($u['codigo']), mb_strtolower($buscar))
                || str_contains(mb_strtolower($u['responsable']['nombre'] ?? ''), mb_strtolower($buscar));
        }));

        return [
            'periodo' => $periodo,
            'resumen' => $this->resumen($mes, $periodo, $hoy),
            'conteos' => $conteos,
            'unidades' => array_slice($visibles, ($pagina - 1) * $porPagina, $porPagina),
            'total' => count($visibles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(string $mes, string $periodo, string $hoy): array
    {
        $emitido = DB::selectOne('select coalesce(sum(monto),0)::numeric(14,2) as emitido, coalesce(sum(pagado),0)::numeric(14,2) as cobrado, count(distinct unidad_id) as unidades from cuotas where periodo = ?', [$mes]);
        $vencido = DB::selectOne('select coalesce(sum(monto - pagado),0)::numeric(14,2) as saldo, count(distinct unidad_id) as unidades from cuotas where periodo < ? and monto > pagado and vence_el < ?', [$mes, $hoy]);
        $porAprobar = DB::selectOne("select coalesce(sum(monto),0)::numeric(14,2) as monto, count(*) as pagos from pagos where estado = 'pendiente'");
        $periodoFinanciero = PeriodoFinanciero::query()->where('periodo', $mes)->first();
        $config = ConfiguracionCobro::query()->first();

        $esperado = Dinero::centavos((string) $emitido->emitido);

        return [
            'emitido' => ['monto' => (string) $emitido->emitido, 'unidades' => (int) $emitido->unidades],
            'cobrado' => ['monto' => (string) $emitido->cobrado, 'porcentaje' => $esperado > 0 ? round(Dinero::centavos((string) $emitido->cobrado) * 100 / $esperado, 1) : 0],
            'por_aprobar' => ['monto' => (string) $porAprobar->monto, 'comprobantes' => (int) $porAprobar->pagos],
            'vencido_anterior' => ['monto' => (string) $vencido->saldo, 'unidades' => (int) $vencido->unidades],
            'emitida' => $periodoFinanciero !== null,
            'estado' => $periodoFinanciero?->estado,
            'emitido_en' => $periodoFinanciero?->emitido_en?->timezone('America/Guayaquil')->toIso8601String(),
            'metodo' => $config?->metodo,
            'dia_vencimiento' => $config?->dia_vencimiento,
        ];
    }

    /**
     * Unidades con un pago pendiente que declara alguna cuota de ese mes.
     *
     * @return list<int>
     */
    private function unidadesConPagoEnRevision(string $periodo): array
    {
        return DB::table('pagos')
            ->where('estado', 'pendiente')
            ->whereRaw('cuotas_declaradas @> ?::jsonb', [json_encode([['periodo' => $periodo]])])
            ->pluck('unidad_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
