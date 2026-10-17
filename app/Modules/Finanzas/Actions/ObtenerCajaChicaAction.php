<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\CajaChica;
use App\Modules\Finanzas\Models\CajaChicaMovimiento;
use App\Modules\Finanzas\Models\Gasto;

/**
 * Consulta: el estado de la caja chica: el fondo, lo gastado desde la última reposición, lo
 * disponible, los movimientos abiertos (con un enlace temporal a su respaldo) y la última reposición.
 */
final class ObtenerCajaChicaAction
{
    public function __construct(private readonly ArchivosCondominio $archivos) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $caja = CajaChica::query()->first();
        $abiertos = CajaChicaMovimiento::query()->where('estado', CajaChicaMovimiento::ABIERTO)->orderByDesc('fecha')->orderByDesc('id')->get();
        $gastado = Dinero::suma($abiertos->map(fn (CajaChicaMovimiento $m) => (string) $m->monto));
        $fondo = $caja === null ? 0 : Dinero::centavos((string) $caja->fondo);

        $ultima = CajaChicaMovimiento::query()->where('estado', CajaChicaMovimiento::REPUESTO)->whereNotNull('gasto_id')->orderByDesc('id')->first();
        $gasto = $ultima === null ? null : Gasto::query()->find($ultima->gasto_id);

        return [
            'configurada' => $caja !== null,
            'fondo' => Dinero::texto($fondo),
            'responsable' => $caja?->responsable_nombre,
            'gastado' => Dinero::texto($gastado),
            'disponible' => Dinero::texto($fondo - $gastado),
            'movimientos' => $abiertos->map(fn (CajaChicaMovimiento $m): array => [
                'id' => $m->id, 'fecha' => $m->fecha->toDateString(), 'concepto' => $m->concepto, 'comercio' => $m->comercio,
                'monto' => (string) $m->monto,
                'respaldo_url' => $this->archivos->existe($m->respaldo_ruta) ? $this->archivos->urlTemporal($m->respaldo_ruta) : null,
            ])->values()->all(),
            'ultima_reposicion' => $gasto === null ? null : [
                'fecha' => $gasto->fecha_emision->toDateString(), 'gasto_id' => $gasto->id, 'gasto_numero' => $gasto->numero, 'monto' => (string) $gasto->total,
                'estado' => $gasto->estado,
            ],
        ];
    }
}
