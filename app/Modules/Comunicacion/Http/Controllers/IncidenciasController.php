<?php

namespace App\Modules\Comunicacion\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Comunicacion\Actions\DetalleIncidenciaAction;
use App\Modules\Comunicacion\Actions\GestionarIncidenciaAction;
use App\Modules\Comunicacion\Actions\ListarMisIncidenciasAction;
use App\Modules\Comunicacion\Actions\ReportarIncidenciaAction;
use App\Modules\Comunicacion\Actions\ResponderIncidenciaResidenteAction;
use App\Modules\Comunicacion\Actions\TableroIncidenciasAction;
use App\Modules\Comunicacion\Models\Incidencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Incidencias: el residente (o el guardia) reporta y responde; la administración las gestiona.
 */
class IncidenciasController
{
    public function mias(Request $request, ListarMisIncidenciasAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function reportar(Request $request, ReportarIncidenciaAction $reportar, DetalleIncidenciaAction $detalle): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['required', 'string', 'min:3', 'max:2000'],
            'categoria' => ['required', 'in:'.implode(',', Incidencia::CATEGORIAS)],
            'unidad_id' => ['sometimes', 'nullable', 'integer'],
            'fotos' => ['sometimes', 'array', 'max:'.Incidencia::MAXIMO_FOTOS],
            'fotos.*' => ['file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
        ], [
            'titulo.required' => 'Escribe un título corto.',
            'titulo.min' => 'El título es muy corto.',
            'descripcion.required' => 'Cuéntanos qué pasa.',
            'categoria.required' => 'Elige una categoría.',
            'fotos.max' => 'Puedes adjuntar hasta '.Incidencia::MAXIMO_FOTOS.' fotos.',
            'fotos.*.mimes' => 'Las fotos deben ser JPG o PNG.',
            'fotos.*.max' => 'Una foto pesa más de 5 MB.',
        ]);

        $i = $reportar->execute($this->usuario($request), trim($d['titulo']), trim($d['descripcion']), $d['categoria'], isset($d['unidad_id']) ? (int) $d['unidad_id'] : null, $request->file('fotos', []));

        return ApiResponse::created(['id' => $i->id, 'numero' => $i->numero], message: "Incidencia #{$i->numero} reportada. La administración la revisará.");
    }

    public function comentarResidente(Request $request, ResponderIncidenciaResidenteAction $responder, int $incidencia): JsonResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'min:1', 'max:1000']], ['texto.required' => 'Escribe tu mensaje.']);
        $responder->comentar($this->usuario($request), $incidencia, trim($d['texto']));

        return ApiResponse::created(null, message: 'Mensaje enviado.');
    }

    public function reabrir(Request $request, ResponderIncidenciaResidenteAction $responder, int $incidencia): JsonResponse
    {
        $d = $request->validate(['texto' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $responder->reabrir($this->usuario($request), $incidencia, $d['texto'] ?? null);

        return ApiResponse::ok(null, message: 'Incidencia reabierta. La administración volverá a revisarla.');
    }

    public function confirmar(Request $request, ResponderIncidenciaResidenteAction $responder, int $incidencia): JsonResponse
    {
        $responder->confirmar($this->usuario($request), $incidencia);

        return ApiResponse::ok(null, message: 'Gracias. La incidencia quedó cerrada.');
    }

    public function tablero(TableroIncidenciasAction $tablero): JsonResponse
    {
        return ApiResponse::ok($tablero->execute());
    }

    public function detalle(DetalleIncidenciaAction $detalle, int $incidencia): JsonResponse
    {
        return ApiResponse::ok($detalle->execute(Incidencia::query()->findOrFail($incidencia), true));
    }

    public function comentar(Request $request, GestionarIncidenciaAction $gestionar, int $incidencia): JsonResponse
    {
        $d = $request->validate([
            'texto' => ['required', 'string', 'min:1', 'max:1000'],
            'interna' => ['sometimes', 'boolean'],
        ], ['texto.required' => 'Escribe el comentario.']);
        $gestionar->comentar($this->usuario($request), $incidencia, trim($d['texto']), (bool) ($d['interna'] ?? false));

        return ApiResponse::created(null, message: ($d['interna'] ?? false) ? 'Nota interna guardada.' : 'Comentario enviado al residente.');
    }

    public function estado(Request $request, GestionarIncidenciaAction $gestionar, int $incidencia): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', 'in:'.implode(',', Incidencia::ESTADOS)]], ['estado.required' => 'Indica el nuevo estado.']);
        $i = $gestionar->cambiarEstado($this->usuario($request), $incidencia, $d['estado']);

        return ApiResponse::ok(['id' => $i->id, 'estado' => $i->estado], message: "Incidencia #{$i->numero} actualizada.");
    }

    public function ajustar(Request $request, GestionarIncidenciaAction $gestionar, int $incidencia): JsonResponse
    {
        $d = $request->validate([
            'prioridad' => ['sometimes', 'in:'.implode(',', Incidencia::PRIORIDADES)],
            'categoria' => ['sometimes', 'in:'.implode(',', Incidencia::CATEGORIAS)],
        ]);
        $i = $gestionar->ajustar($incidencia, $d['prioridad'] ?? null, $d['categoria'] ?? null);

        return ApiResponse::ok(['id' => $i->id, 'prioridad' => $i->prioridad, 'categoria' => $i->categoria]);
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
