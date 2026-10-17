<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\ExportarReporteAction;
use App\Modules\Finanzas\Actions\ListarExportacionesAction;
use App\Modules\Finanzas\Services\ReportesFinancieros;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes financieros: verlos en pantalla, exportarlos a Excel y la bitácora de exportaciones.
 */
class ReporteController
{
    public function show(Request $request, ReportesFinancieros $reportes, Calendario $calendario, string $reporte): JsonResponse
    {
        return ApiResponse::ok($reportes->generar($reporte, $this->periodo($request, $calendario)));
    }

    public function excel(Request $request, ExportarReporteAction $exportar, Calendario $calendario, string $reporte): BinaryFileResponse
    {
        $r = $exportar->execute($request->user(), $reporte, $this->periodo($request, $calendario));

        return response()->download($r['ruta'], $r['nombre'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Codigo-Exportacion' => $r['codigo'],
        ])->deleteFileAfterSend();
    }

    public function exportaciones(ListarExportacionesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    private function periodo(Request $request, Calendario $calendario): string
    {
        $d = $request->validate(['periodo' => ['sometimes', 'string', 'date_format:Y-m']], ['periodo.date_format' => 'El mes no es válido (año-mes, por ejemplo 2026-09).']);

        return $d['periodo'] ?? CarbonImmutable::parse($calendario->hoy())->format('Y-m');
    }
}
