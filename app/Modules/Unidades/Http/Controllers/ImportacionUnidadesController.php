<?php

namespace App\Modules\Unidades\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Finanzas\Actions\ObtenerMetodoCobroAction;
use App\Modules\Unidades\Actions\ImportarUnidadesAction;
use App\Modules\Unidades\Http\Requests\ImportarUnidadesRequest;
use App\Modules\Unidades\Services\PlantillaUnidades;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportacionUnidadesController
{
    /** Excel de ejemplo con las columnas que pide el método de cobro del condominio. */
    public function plantilla(ObtenerMetodoCobroAction $metodo, PlantillaUnidades $plantilla): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla-unidades-').'.xlsx';
        $plantilla->generar($ruta, $metodo->execute());

        return response()->download($ruta, 'plantilla-unidades.xlsx')->deleteFileAfterSend();
    }

    /**
     * Sin confirmar: vista previa con errores por fila, sin guardar nada.
     * Con confirmar=1: crea todas las unidades o ninguna.
     */
    public function importar(ImportarUnidadesRequest $request, ImportarUnidadesAction $action): JsonResponse
    {
        $confirmar = $request->boolean('confirmar');
        $resultado = $action->execute($request->file('archivo')->getRealPath(), $confirmar);

        return $confirmar
            ? ApiResponse::created($resultado, message: 'Unidades importadas.')
            : ApiResponse::ok($resultado);
    }
}
