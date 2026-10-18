<?php

namespace App\Modules\Garita\Http\Controllers;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Garita\Actions\BitacoraGaritaAction;
use App\Modules\Garita\Actions\PanelGaritaAction;
use App\Modules\Garita\Actions\QrVigentesAction;
use App\Modules\Garita\Actions\RegistrarAccesoAction;
use App\Modules\Garita\Actions\RegistrarSalidaAction;
use App\Modules\Garita\Actions\SincronizarAccesosAction;
use App\Modules\Garita\Actions\ValidarQrAction;
use App\Modules\Garita\Models\AccesoGarita;
use App\Modules\Garita\Services\ExcelBitacoraGarita;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Garita: validar el QR, registrar ingresos y salidas, ver quién está dentro y la bitácora de la administración.
 */
class GaritaController
{
    public function panel(PanelGaritaAction $panel): JsonResponse
    {
        return ApiResponse::ok($panel->execute());
    }

    public function qrVigentes(QrVigentesAction $vigentes): JsonResponse
    {
        return ApiResponse::ok($vigentes->execute());
    }

    public function sincronizar(Request $request, SincronizarAccesosAction $sincronizar): JsonResponse
    {
        $d = $request->validate([
            'ingresos' => ['present', 'array', 'max:'.SincronizarAccesosAction::MAXIMO],
            'ingresos.*.uuid' => ['required', 'uuid'],
            'ingresos.*.token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ingresos.*.tipo' => ['required', 'in:'.implode(',', AccesoGarita::TIPOS)],
            'ingresos.*.nombre' => ['required', 'string', 'min:3', 'max:120'],
            'ingresos.*.documento' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'ingresos.*.placa' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{2,3}-?[0-9]{3,4}$/'],
            'ingresos.*.unidad_codigo' => ['sometimes', 'nullable', 'string', 'max:20'],
            'ingresos.*.destino' => ['sometimes', 'nullable', 'string', 'max:80'],
            'ingresos.*.autorizado_por' => ['required', 'in:'.implode(',', AccesoGarita::AUTORIZADO_POR)],
            'ingresos.*.entrada_en' => ['required', 'date'],
            'ingresos.*.salida_en' => ['sometimes', 'nullable', 'date'],
            'salidas' => ['sometimes', 'array', 'max:'.SincronizarAccesosAction::MAXIMO],
            'salidas.*.acceso_id' => ['required', 'integer'],
            'salidas.*.salida_en' => ['required', 'date'],
        ], [
            'ingresos.max' => 'Se pueden sincronizar hasta '.SincronizarAccesosAction::MAXIMO.' ingresos por vez.',
            'ingresos.*.uuid.uuid' => 'Un ingreso no tiene un identificador válido.',
        ]);

        return ApiResponse::ok($sincronizar->execute($this->usuario($request), $d['ingresos'], $d['salidas'] ?? []));
    }

    public function validarQr(Request $request, ValidarQrAction $validar): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:64']], ['token.required' => 'Escanea o escribe el código del QR.']);

        return ApiResponse::ok($validar->execute(trim($d['token'])));
    }

    public function ingreso(Request $request, RegistrarAccesoAction $registrar): JsonResponse
    {
        $d = $request->validate([
            'token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tipo' => ['required_without:token', 'in:'.implode(',', AccesoGarita::TIPOS)],
            'nombre' => ['required_without:token', 'string', 'min:3', 'max:120'],
            'documento' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'placa' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{2,3}-?[0-9]{3,4}$/'],
            'unidad_codigo' => ['sometimes', 'nullable', 'string', 'max:20'],
            'destino' => ['sometimes', 'nullable', 'string', 'max:80'],
            'autorizado_por' => ['required_without:token', 'in:llamada,administracion,app_residente'],
        ], [
            'tipo.required_without' => 'Elige si es visita, proveedor o delivery.',
            'nombre.required_without' => 'Escribe el nombre.',
            'nombre.min' => 'El nombre es muy corto.',
            'documento.regex' => 'La cédula o el documento no es válido.',
            'placa.regex' => 'La placa no es válida (ej. PDQ-3321).',
            'autorizado_por.required_without' => 'Indica quién autorizó el ingreso.',
        ]);

        $a = $registrar->execute($this->usuario($request), $d);

        return ApiResponse::created(['id' => $a->id, 'nombre' => $a->nombre, 'estado' => $a->estado], message: 'Ingreso registrado.');
    }

    public function salida(Request $request, RegistrarSalidaAction $salida, int $acceso): JsonResponse
    {
        $a = $salida->execute($this->usuario($request), $acceso);

        return ApiResponse::ok(['id' => $a->id, 'estado' => $a->estado], message: 'Salida registrada.');
    }

    public function bitacora(Request $request, BitacoraGaritaAction $bitacora): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tipo' => ['sometimes', 'in:'.implode(',', AccesoGarita::TIPOS)],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.']);

        if (isset($d['desde'], $d['hasta']) && (strtotime($d['hasta']) - strtotime($d['desde'])) > 31 * 86400) {
            throw new ApiException('RANGO_MUY_LARGO', 'Elige un rango de hasta 31 días.', 422);
        }

        return ApiResponse::ok($bitacora->execute($d['desde'] ?? null, $d['hasta'] ?? null, $d['tipo'] ?? null, $d['q'] ?? null));
    }

    /** Excel con la bitácora del rango (hasta 5000 filas): mismos filtros que la pantalla. */
    public function exportarBitacora(Request $request, BitacoraGaritaAction $bitacora, ExcelBitacoraGarita $excel): BinaryFileResponse
    {
        $d = $request->validate([
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'tipo' => ['sometimes', 'in:'.implode(',', AccesoGarita::TIPOS)],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.']);

        if (isset($d['desde'], $d['hasta']) && (strtotime($d['hasta']) - strtotime($d['desde'])) > 31 * 86400) {
            throw new ApiException('RANGO_MUY_LARGO', 'Elige un rango de hasta 31 días.', 422);
        }

        $datos = $bitacora->execute($d['desde'] ?? null, $d['hasta'] ?? null, $d['tipo'] ?? null, $d['q'] ?? null, BitacoraGaritaAction::MAXIMO_EXPORTACION);
        $ruta = tempnam(sys_get_temp_dir(), 'bitacora-garita-').'.xlsx';
        $excel->generar($ruta, $datos['accesos']);

        return response()->download($ruta, "bitacora-garita-{$datos['desde']}-{$datos['hasta']}.xlsx")->deleteFileAfterSend();
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
