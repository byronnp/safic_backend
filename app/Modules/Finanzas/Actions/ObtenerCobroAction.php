<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Audit\Auditoria;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finanzas\Models\CobroValorTipo;
use App\Modules\Finanzas\Models\ConfiguracionCobro;
use App\Modules\Unidades\Actions\ResumenCobroUnidadesAction;
use Illuminate\Support\Facades\DB;

/**
 * Consulta pública del módulo Finanzas para la pantalla Configuración › Cobro de
 * cuotas: la configuración vigente, las unidades por tipo (para proyectar la
 * próxima emisión) y el historial de cambios, que sale de la auditoría.
 */
final class ObtenerCobroAction
{
    /** Campos que se muestran en el historial, en este orden. */
    private const CAMPOS = ['metodo', 'cuota_general', 'presupuesto_mensual', 'dia_vencimiento', 'aplica_desde'];

    /** @var array<int, string> id del valor → tipo de unidad */
    private array $tipos = [];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ResumenCobroUnidadesAction $unidades,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $this->tenant->require();
        $configuracion = ConfiguracionCobro::query()->first();
        $resumen = $this->unidades->execute();

        return [
            'configurado' => $configuracion !== null,
            'metodo' => $configuracion->metodo ?? ConfiguracionCobro::METODO_GENERAL,
            'cuota_general' => $configuracion?->cuota_general,
            'presupuesto_mensual' => $configuracion?->presupuesto_mensual,
            'valores_tipo' => CobroValorTipo::query()->orderBy('id')->get()
                ->map(fn (CobroValorTipo $v) => ['tipo' => $v->tipo_unidad, 'valor' => $v->valor])
                ->values()
                ->all(),
            'dia_vencimiento' => $configuracion->dia_vencimiento ?? 10,
            'aplica_desde' => $configuracion?->aplica_desde->format('Y-m'),
            'unidades' => [
                'por_tipo' => $resumen['por_tipo'],
                'con_cupo' => $resumen['con_cupo'],
                'suma_cuotas' => $resumen['suma_cuotas'],
                'sin_alicuota' => $resumen['sin_alicuota'],
                'sin_cuota_mensual' => $resumen['sin_cuota_mensual'],
            ],
            'historial' => $this->historial(),
        ];
    }

    /**
     * Un registro por cambio guardado (los cambios del mismo usuario en el mismo
     * segundo se juntan), el más reciente primero.
     *
     * @return list<array{fecha: string, quien: string, inicial: bool, cambios: list<array{campo: string, antes: string|null, despues: string|null}>}>
     */
    private function historial(): array
    {
        $auditorias = Auditoria::query()
            ->whereIn('auditable_type', [(new ConfiguracionCobro)->getMorphClass(), (new CobroValorTipo)->getMorphClass()])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $nombres = $this->nombres($auditorias->pluck('user_id')->filter()->unique()->all());
        // Una actualización solo guarda lo que cambió (no el tipo de unidad): se busca por id
        $this->tipos = CobroValorTipo::query()->whereIn('id', $auditorias->where('auditable_type', (new CobroValorTipo)->getMorphClass())->pluck('auditable_id'))
            ->pluck('tipo_unidad', 'id')->all();

        $grupos = [];
        foreach ($auditorias->reverse() as $auditoria) {
            $clave = $auditoria->user_id.'|'.$auditoria->created_at?->format('Y-m-d H:i:s');
            $grupos[$clave] ??= [
                'fecha' => (string) $auditoria->created_at?->toIso8601String(),
                'quien' => $auditoria->user_id === null ? 'Sistema' : ($nombres[$auditoria->user_id] ?? 'Equipo SAFIC'),
                'inicial' => false,
                'cambios' => [],
            ];

            foreach ($this->cambios($auditoria) as $cambio) {
                $grupos[$clave]['cambios'][] = $cambio;
            }
            if ($auditoria->event === 'created' && $auditoria->auditable_type === (new ConfiguracionCobro)->getMorphClass()) {
                $grupos[$clave]['inicial'] = true;
            }
        }

        return array_values(array_reverse(array_filter($grupos, fn (array $g) => $g['cambios'] !== [])));
    }

    /**
     * @return list<array{campo: string, antes: string|null, despues: string|null}>
     */
    private function cambios(Auditoria $auditoria): array
    {
        $antes = $auditoria->old_values;
        $despues = $auditoria->new_values;

        if ($auditoria->auditable_type === (new CobroValorTipo)->getMorphClass()) {
            $tipo = $despues['tipo_unidad'] ?? $antes['tipo_unidad'] ?? $this->tipos[$auditoria->auditable_id] ?? null;
            $valorAntes = $auditoria->event === 'created' ? null : ($antes['valor'] ?? null);
            $valorDespues = $auditoria->event === 'deleted' ? null : ($despues['valor'] ?? null);

            return $tipo === null || $valorAntes === $valorDespues ? [] : [
                ['campo' => 'valor_tipo:'.$tipo, 'antes' => $valorAntes, 'despues' => $valorDespues],
            ];
        }

        $cambios = [];
        foreach (self::CAMPOS as $campo) {
            if (! array_key_exists($campo, $despues) && ! array_key_exists($campo, $antes)) {
                continue;
            }
            $a = $auditoria->event === 'created' ? null : $this->normalizar($campo, $antes[$campo] ?? null);
            $d = $this->normalizar($campo, $despues[$campo] ?? null);
            if ($a !== $d && ! ($a === null && $d === null)) {
                $cambios[] = ['campo' => $campo, 'antes' => $a, 'despues' => $d];
            }
        }

        return $cambios;
    }

    private function normalizar(string $campo, mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        // aplica_desde se guarda como fecha: el historial habla de meses
        return $campo === 'aplica_desde' ? substr((string) $valor, 0, 7) : (string) $valor;
    }

    /**
     * Nombre de quien hizo el cambio si es miembro del condominio; las acciones del
     * equipo de la plataforma se muestran como "Equipo SAFIC".
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function nombres(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $miembros = DB::table('condominio_user')
            ->where('condominio_id', $this->tenant->require())
            ->whereIn('user_id', $ids)
            ->pluck('user_id')
            ->all();

        return User::query()->whereIn('id', $miembros)->pluck('name', 'id')->all();
    }
}
