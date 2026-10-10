<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use Illuminate\Support\Facades\DB;

/**
 * Proveedores con lo que se les debe, su estado y su cuenta para pagos (con el cambio en espera,
 * si lo hay). El número de cuenta sale completo: ver esta pantalla exige finanzas.ver.
 */
final class ListarProveedoresAction
{
    public const FILTROS = ['todos', 'con_saldo', 'cuenta_en_cambio'];

    /** Días antes del vencimiento desde los que se avisa «vence pronto». */
    private const DIAS_AVISO = 7;

    public function __construct(private readonly Calendario $calendario) {}

    /**
     * @return array{proveedores: list<array<string, mixed>>, conteos: array<string, int>}
     */
    public function execute(string $filtro, string $buscar): array
    {
        $hoy = $this->calendario->hoy();
        $aviso = date('Y-m-d', strtotime($hoy.' +'.self::DIAS_AVISO.' days'));

        // Por proveedor: saldo, facturas por aprobar y próximo vencimiento de lo que se debe
        $gastos = DB::table('gastos')
            ->whereNotIn('estado', ['rechazada'])
            ->groupBy('proveedor_id')
            ->selectRaw("proveedor_id, count(*) as facturas, sum(total - pagado) as saldo, count(*) filter (where estado = 'por_aprobar') as por_aprobar, min(vence_el) filter (where total > pagado) as vence")
            ->get()->keyBy('proveedor_id');

        $cuentas = ProveedorCuenta::query()->whereIn('estado', ['activa', 'pendiente'])->get()->groupBy('proveedor_id');

        $filas = Proveedor::query()->where('activo', true)->orderBy('razon_social')->get()
            ->map(function (Proveedor $p) use ($gastos, $cuentas, $aviso): array {
                $g = $gastos->get($p->id);
                $grupo = $cuentas->get($p->id) ?? collect();
                $vigente = $grupo->firstWhere('estado', 'activa');
                $pendiente = $grupo->firstWhere('estado', 'pendiente');
                $saldo = Dinero::centavos($g->saldo ?? 0);
                $vence = $g->vence ?? null;

                return [
                    'id' => $p->id,
                    'ruc' => $p->ruc,
                    'razon_social' => $p->razon_social,
                    'categoria' => $p->categoria,
                    'email' => $p->email,
                    'telefono' => $p->telefono,
                    'saldo_por_pagar' => Dinero::texto($saldo),
                    'estado' => match (true) {
                        $g === null => 'sin_facturas',
                        (int) $g->por_aprobar > 0 => 'por_aprobar',
                        $saldo > 0 && $vence !== null && $vence <= $aviso => 'vence_pronto',
                        default => 'al_dia',
                    },
                    'proximo_vencimiento' => $saldo > 0 ? $vence : null,
                    'cuenta' => $vigente ? self::cuenta($vigente) : null,
                    'cambio_de_cuenta' => $pendiente ? self::cuenta($pendiente) + [
                        'activa_en' => $pendiente->activa_en?->toIso8601String(),
                        'solicitado_por' => $pendiente->solicitada_por !== null
                            ? DB::table('users')->where('id', $pendiente->solicitada_por)->value('name')
                            : null,
                        'solicitado_en' => $pendiente->created_at?->toIso8601String(),
                    ] : null,
                ];
            });

        $conteos = [
            'todos' => $filas->count(),
            'con_saldo' => $filas->filter(fn (array $f) => Dinero::centavos($f['saldo_por_pagar']) > 0)->count(),
            'cuenta_en_cambio' => $filas->filter(fn (array $f) => $f['cambio_de_cuenta'] !== null)->count(),
        ];

        $lista = $filas->all();
        if ($filtro === 'con_saldo') {
            $lista = array_filter($lista, fn (array $f): bool => Dinero::centavos($f['saldo_por_pagar']) > 0);
        } elseif ($filtro === 'cuenta_en_cambio') {
            $lista = array_filter($lista, fn (array $f): bool => $f['cambio_de_cuenta'] !== null);
        }
        if ($buscar !== '') {
            $lista = array_filter($lista, fn (array $f): bool => str_contains(mb_strtolower($f['razon_social'].' '.$f['ruc']), mb_strtolower($buscar)));
        }

        return ['proveedores' => array_values($lista), 'conteos' => $conteos];
    }

    /** @return array<string, mixed> */
    public function uno(int $id): array
    {
        $todos = $this->execute('todos', '')['proveedores'];

        return collect($todos)->firstWhere('id', $id) ?? [];
    }

    /** @return array{id: int, banco: string, tipo: string, numero: string, titular: string} */
    private static function cuenta(ProveedorCuenta $c): array
    {
        return ['id' => $c->id, 'banco' => $c->banco, 'tipo' => $c->tipo, 'numero' => $c->numero, 'titular' => $c->titular];
    }
}
