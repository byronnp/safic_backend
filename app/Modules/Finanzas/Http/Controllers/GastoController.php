<?php

namespace App\Modules\Finanzas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Modules\Finanzas\Actions\ListarGastosAction;
use App\Modules\Finanzas\Actions\RegistrarGastoAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuentas por pagar: facturas de proveedores.
 */
class GastoController
{
    public function index(Request $request, ListarGastosAction $listar): JsonResponse
    {
        $f = $request->validate([
            'filtro' => ['sometimes', Rule::in(ListarGastosAction::FILTROS)],
            'buscar' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['filtro.in' => 'Elige todas, por aprobar, por pagar, pagadas o rechazadas.']);

        $r = $listar->execute($f['filtro'] ?? 'todas', trim((string) ($f['buscar'] ?? '')));

        return ApiResponse::ok($r['gastos'], ['resumen' => $r['resumen'], 'conteos' => $r['conteos']]);
    }

    public function importarXml(Request $request, RegistrarGastoAction $registrar, ListarGastosAction $listar, Calendario $calendario): JsonResponse
    {
        $datos = $request->validate([
            'xml' => ['required', 'file', 'max:1024', 'mimetypes:text/xml,application/xml,text/plain'],
            'pdf' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:5120'],
            'vence_el' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
        ], [
            'xml.required' => 'Elige el archivo XML de la factura.',
            'xml.mimetypes' => 'El archivo debe ser el XML de la factura.',
            'xml.max' => 'El XML pesa más de 1 MB.',
            'pdf.mimes' => 'El adjunto debe ser un PDF.',
            'pdf.max' => 'El PDF pesa más de 5 MB.',
            'vence_el.date_format' => 'La fecha de vencimiento no es válida.',
        ]);

        $gasto = $registrar->desdeXml($request->user(), $request->file('xml'), $request->file('pdf'), [
            'vence_el' => $datos['vence_el'] ?? null, 'categoria' => $datos['categoria'] ?? null,
        ]);
        $gasto->load('proveedor:id,razon_social,ruc');

        return ApiResponse::created($listar->fila($gasto, $calendario->hoy()), message: 'Factura registrada. Queda por aprobar.');
    }

    public function store(Request $request, RegistrarGastoAction $registrar, ListarGastosAction $listar, Calendario $calendario): JsonResponse
    {
        $d = $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'numero' => ['required', 'string', 'regex:/^\d{3}-\d{3}-\d{9}$/'],
            'fecha_emision' => ['required', 'date_format:Y-m-d'],
            'vence_el' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'subtotal' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'con_iva' => ['required', 'boolean'],
            'categoria' => ['sometimes', 'nullable', 'string', 'max:60'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:200'],
            'pdf' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:5120'],
        ], [
            'proveedor_id.required' => 'Elige el proveedor.',
            'numero.required' => 'Escribe el número de la factura.',
            'numero.regex' => 'El número tiene el formato 001-001-000000123.',
            'fecha_emision.required' => 'Elige la fecha de emisión.',
            'subtotal.required' => 'Escribe el subtotal.',
            'subtotal.decimal' => 'El subtotal tiene hasta dos decimales.',
            'subtotal.gt' => 'El subtotal debe ser mayor a cero.',
            'con_iva.required' => 'Indica si la factura lleva IVA.',
        ]);

        $gasto = $registrar->manual($request->user(), [
            'proveedor_id' => (int) $d['proveedor_id'], 'numero' => $d['numero'], 'fecha_emision' => $d['fecha_emision'],
            'vence_el' => $d['vence_el'] ?? null, 'subtotal' => (string) $d['subtotal'], 'con_iva' => (bool) $d['con_iva'],
            'categoria' => $d['categoria'] ?? null, 'descripcion' => $d['descripcion'] ?? null,
        ], $request->file('pdf'));
        $gasto->load('proveedor:id,razon_social,ruc');

        return ApiResponse::created($listar->fila($gasto, $calendario->hoy()), message: 'Factura registrada. Queda por aprobar.');
    }
}
