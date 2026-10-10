<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\Gasto;

/**
 * Cuentas por pagar: las facturas de proveedores con su saldo, de la más urgente a la más
 * reciente. `vencida` es una marca de lectura (venció y aún se debe).
 */
final class ListarGastosAction
{
    public const FILTROS = ['todas', 'por_aprobar', 'por_pagar', 'pagadas', 'rechazadas'];

    public function __construct(private readonly Calendario $calendario) {}

    /**
     * @return array{gastos: list<array<string, mixed>>, resumen: array<string, string|int>, conteos: array<string, int>}
     */
    public function execute(string $filtro, string $buscar): array
    {
        $hoy = $this->calendario->hoy();
        $umbral = Dinero::centavos(ConfiguracionFinanzas::vigente()->umbral_segunda_aprobacion);
        $todos = Gasto::query()->with('proveedor:id,razon_social,ruc')->orderBy('vence_el')->orderBy('id')->get();

        $grupo = fn (Gasto $g): string => match ($g->estado) {
            'por_aprobar' => 'por_aprobar',
            'aprobada' => 'por_pagar',
            'pagada' => 'pagadas',
            default => 'rechazadas',
        };

        $conteos = ['todas' => $todos->count()];
        foreach (self::FILTROS as $f) {
            if ($f !== 'todas') {
                $conteos[$f] = $todos->filter(fn (Gasto $g) => $grupo($g) === $f)->count();
            }
        }

        $porAprobar = $todos->filter(fn (Gasto $g) => $grupo($g) === 'por_aprobar');
        $porPagar = $todos->filter(fn (Gasto $g) => $grupo($g) === 'por_pagar');
        $vencidas = $porPagar->filter(fn (Gasto $g) => $g->vence_el->toDateString() < $hoy);
        $saldo = fn ($c): int => Dinero::suma($c->map(fn (Gasto $g) => Dinero::texto(Dinero::centavos($g->total) - Dinero::centavos($g->pagado))));

        $resumen = [
            'por_aprobar_monto' => Dinero::texto($saldo($porAprobar)), 'por_aprobar_facturas' => $porAprobar->count(),
            'por_pagar_monto' => Dinero::texto($saldo($porPagar)), 'por_pagar_facturas' => $porPagar->count(),
            'vencido_monto' => Dinero::texto($saldo($vencidas)), 'vencido_facturas' => $vencidas->count(),
        ];

        $filas = $todos
            ->when($filtro !== 'todas', fn ($c) => $c->filter(fn (Gasto $g) => $grupo($g) === $filtro))
            ->when($buscar !== '', fn ($c) => $c->filter(fn (Gasto $g) => str_contains(
                mb_strtolower($g->proveedor->razon_social.' '.$g->numero.' '.$g->proveedor->ruc), mb_strtolower($buscar))))
            ->map(fn (Gasto $g): array => $this->fila($g, $hoy, $umbral))
            ->values()->all();

        return ['gastos' => $filas, 'resumen' => $resumen, 'conteos' => $conteos];
    }

    /** @return array<string, mixed> */
    public function fila(Gasto $g, string $hoy, ?int $umbral = null): array
    {
        $umbral ??= Dinero::centavos(ConfiguracionFinanzas::vigente()->umbral_segunda_aprobacion);
        $requiereSegundo = Dinero::centavos($g->total) > $umbral;
        $saldo = Dinero::centavos($g->total) - Dinero::centavos($g->pagado);

        return [
            'id' => $g->id,
            'proveedor' => ['id' => $g->proveedor_id, 'razon_social' => $g->proveedor->razon_social, 'ruc' => $g->proveedor->ruc],
            'numero' => $g->numero,
            'categoria' => $g->categoria,
            'descripcion' => $g->descripcion,
            'origen' => $g->origen,
            'fecha_emision' => $g->fecha_emision->toDateString(),
            'vence_el' => $g->vence_el->toDateString(),
            'subtotal' => $g->subtotal, 'iva' => $g->iva, 'total' => $g->total,
            'pagado' => $g->pagado,
            'saldo' => Dinero::texto($saldo),
            'pagada_parcial' => $g->estado === 'aprobada' && Dinero::centavos($g->pagado) > 0,
            'requiere_segunda_aprobacion' => $requiereSegundo,
            // Qué aprobación falta: 1, 2, o null si ya no espera ninguna
            'nivel_pendiente' => $g->estado === 'por_aprobar' ? $g->nivel_aprobado + 1 : null,
            'motivo_rechazo' => $g->motivo_rechazo,
            'estado' => $g->estado,
            'vencida' => $g->estado === 'aprobada' && $g->vence_el->toDateString() < $hoy,
            'tiene_xml' => $g->xml_ruta !== null,
            'tiene_pdf' => $g->pdf_ruta !== null,
        ];
    }
}
