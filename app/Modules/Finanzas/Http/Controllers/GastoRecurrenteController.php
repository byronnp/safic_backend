<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\GuardarGastoRecurrenteAction;
use App\Modules\Finanzas\Actions\ListarFacturasCandidatasAction;
use App\Modules\Finanzas\Actions\ListarGastosRecurrentesAction;
use App\Modules\Finanzas\Actions\VincularFacturaRecurrenteAction;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gastos recurrentes: el catálogo, lo que pasa cada mes y la factura de cada ocurrencia.
 */
class GastoRecurrenteController
{
    private const MENSAJES = [
        'nombre.required' => 'Escribe el nombre del gasto.',
        'nombre.min' => 'El nombre es muy corto.',
        'proveedor_id.required' => 'Elige el proveedor.',
        'monto.decimal' => 'El monto tiene hasta dos decimales.',
        'monto.gt' => 'El monto debe ser mayor a cero.',
        'dia_mes.required' => 'Elige el día del mes.',
        'dia_mes.between' => 'El día va del 1 al 28.',
    ];

    public function index(Request $request, ListarGastosRecurrentesAction $listar, Calendario $calendario): JsonResponse
    {
        $d = $request->validate(['periodo' => ['sometimes', 'string', 'date_format:Y-m']], ['periodo.date_format' => 'El mes no es válido (año-mes, por ejemplo 2026-10).']);
        $r = $listar->execute($d['periodo'] ?? CarbonImmutable::parse($calendario->hoy())->format('Y-m'));

        return ApiResponse::ok($r['recurrentes'], ['resumen' => $r['resumen']]);
    }

    public function store(Request $request, GuardarGastoRecurrenteAction $guardar, ListarGastosRecurrentesAction $listar, Calendario $calendario): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
            'proveedor_id' => ['required', 'integer'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            'monto' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'dia_mes' => ['required', 'integer', 'between:1,28'],
            'aprobacion_automatica' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        $r = $guardar->crear($datos);

        return ApiResponse::created($this->uno($listar, $calendario, $r->id), message: 'Gasto recurrente creado.');
    }

    public function update(Request $request, GuardarGastoRecurrenteAction $guardar, ListarGastosRecurrentesAction $listar, Calendario $calendario, int $recurrente): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'min:3', 'max:80'],
            'proveedor_id' => ['sometimes', 'integer'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            'monto' => ['sometimes', 'nullable', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'dia_mes' => ['sometimes', 'integer', 'between:1,28'],
            'aprobacion_automatica' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ], self::MENSAJES);

        $guardar->editar($recurrente, $datos);

        return ApiResponse::ok($this->uno($listar, $calendario, $recurrente), message: 'Cambios guardados.');
    }

    public function facturas(ListarFacturasCandidatasAction $listar, int $recurrente): JsonResponse
    {
        return ApiResponse::ok($listar->execute($recurrente));
    }

    public function vincular(Request $request, VincularFacturaRecurrenteAction $vincular, ListarGastosRecurrentesAction $listar, Calendario $calendario, int $recurrente): JsonResponse
    {
        $d = $request->validate([
            'periodo' => ['required', 'string', 'date_format:Y-m'],
            'gasto_id' => ['required', 'integer'],
        ], ['gasto_id.required' => 'Elige la factura.', 'periodo.required' => 'Indica el mes.']);

        $r = $vincular->execute($recurrente, $d['periodo'], (int) $d['gasto_id']);

        return ApiResponse::ok(
            $this->uno($listar, $calendario, $recurrente, $d['periodo']),
            meta: ['aprobada_automaticamente' => $r['aprobada_automaticamente']],
            message: $r['aprobada_automaticamente'] ? 'Factura vinculada y aprobada (nivel 1) automáticamente.' : 'Factura vinculada.',
        );
    }

    /** @return array<string, mixed> */
    private function uno(ListarGastosRecurrentesAction $listar, Calendario $calendario, int $id, ?string $periodo = null): array
    {
        $r = $listar->execute($periodo ?? CarbonImmutable::parse($calendario->hoy())->format('Y-m'));

        return collect($r['recurrentes'])->firstWhere('id', $id) ?? [];
    }
}
