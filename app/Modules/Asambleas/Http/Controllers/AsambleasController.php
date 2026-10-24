<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Asambleas\Actions\AdjuntoDePuntoAction;
use App\Modules\Asambleas\Actions\CancelarAsambleaAction;
use App\Modules\Asambleas\Actions\ConstanciaDeConvocatoriaAction;
use App\Modules\Asambleas\Actions\ConvocarAsambleaAction;
use App\Modules\Asambleas\Actions\EliminarAsambleaAction;
use App\Modules\Asambleas\Actions\EliminarPuntoAction;
use App\Modules\Asambleas\Actions\GuardarAsambleaAction;
use App\Modules\Asambleas\Actions\GuardarConfiguracionAction;
use App\Modules\Asambleas\Actions\GuardarPuntoAction;
use App\Modules\Asambleas\Actions\ListarAsambleasAction;
use App\Modules\Asambleas\Actions\MoverPuntoAction;
use App\Modules\Asambleas\Actions\ObtenerConfiguracionAction;
use App\Modules\Asambleas\Actions\ReenviarConvocatoriaAction;
use App\Modules\Asambleas\Actions\VerAsambleaAction;
use App\Modules\Asambleas\Actions\VerConvocatoriaAction;
use App\Modules\Asambleas\Actions\VerPadronAction;
use App\Modules\Asambleas\Models\Asamblea;
use App\Modules\Asambleas\Models\AsambleaPunto;
use App\Modules\Asambleas\Services\AnticipacionDeConvocatoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Preparar una asamblea: el borrador, su orden del día y las reglas del condominio.
 */
class AsambleasController
{
    public function index(Request $request, ListarAsambleasAction $listar): JsonResponse
    {
        $d = $request->validate(['estado' => ['sometimes', 'string', Rule::in([
            Asamblea::BORRADOR, Asamblea::CONVOCADA, Asamblea::EN_REGISTRO, Asamblea::INSTALADA, Asamblea::EN_VOTACION, Asamblea::CERRADA, Asamblea::ACTA_FIRMADA, Asamblea::CANCELADA,
        ])]]);

        return ApiResponse::ok($listar->execute($d['estado'] ?? null));
    }

    public function padron(VerPadronAction $padron): JsonResponse
    {
        return ApiResponse::ok($padron->execute());
    }

    /** La anticipación de la convocatoria para una fecha, sin guardar nada (el formulario la muestra mientras se escribe). */
    public function anticipacion(Request $request, AnticipacionDeConvocatoria $anticipacion, Calendario $calendario): JsonResponse
    {
        $d = $request->validate(['fecha_hora' => ['required', 'date_format:Y-m-d\TH:i']], [
            'fecha_hora.required' => 'Indica la fecha y la hora.', 'fecha_hora.date_format' => 'La fecha y la hora no son válidas.',
        ]);

        return ApiResponse::ok($anticipacion->de(CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d['fecha_hora'], $calendario->zonaHoraria())));
    }

    public function show(VerAsambleaAction $ver, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($ver->execute($asamblea));
    }

    public function store(Request $request, GuardarAsambleaAction $guardar, VerAsambleaAction $ver): JsonResponse
    {
        $a = $guardar->crear($this->usuario($request), $this->validar($request));

        return ApiResponse::created($ver->execute($a->id), message: 'Borrador guardado.');
    }

    public function update(Request $request, GuardarAsambleaAction $guardar, VerAsambleaAction $ver, int $asamblea): JsonResponse
    {
        $guardar->actualizar($asamblea, $this->validar($request));

        return ApiResponse::ok($ver->execute($asamblea), message: 'Borrador guardado.');
    }

    public function destroy(EliminarAsambleaAction $eliminar, int $asamblea): JsonResponse
    {
        $eliminar->execute($asamblea);

        return ApiResponse::ok(null, message: 'Borrador descartado.');
    }

    public function storePunto(Request $request, GuardarPuntoAction $guardar, VerAsambleaAction $ver, int $asamblea): JsonResponse
    {
        $guardar->crear($asamblea, $this->validarPunto($request));

        return ApiResponse::created($ver->execute($asamblea), message: 'Punto agregado.');
    }

    public function updatePunto(Request $request, GuardarPuntoAction $guardar, VerAsambleaAction $ver, int $asamblea, int $punto): JsonResponse
    {
        $guardar->actualizar($asamblea, $punto, $this->validarPunto($request));

        return ApiResponse::ok($ver->execute($asamblea), message: 'Punto guardado.');
    }

    public function destroyPunto(EliminarPuntoAction $eliminar, VerAsambleaAction $ver, int $asamblea, int $punto): JsonResponse
    {
        $eliminar->execute($asamblea, $punto);

        return ApiResponse::ok($ver->execute($asamblea), message: 'Punto quitado.');
    }

    public function moverPunto(Request $request, MoverPuntoAction $mover, VerAsambleaAction $ver, int $asamblea, int $punto): JsonResponse
    {
        $d = $request->validate(['direccion' => ['required', Rule::in(['sube', 'baja'])]], ['direccion.required' => 'Indica si el punto sube o baja.', 'direccion.in' => 'La dirección no es válida.']);
        $mover->execute($asamblea, $punto, $d['direccion']);

        return ApiResponse::ok($ver->execute($asamblea));
    }

    public function adjuntar(Request $request, AdjuntoDePuntoAction $adjunto, VerAsambleaAction $ver, int $asamblea, int $punto): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240']], [
            'archivo.required' => 'Elige el informe en PDF.', 'archivo.mimes' => 'El informe debe ser un PDF.', 'archivo.max' => 'El informe pesa más de 10 MB.',
        ]);
        $adjunto->subir($asamblea, $punto, $request->file('archivo'));

        return ApiResponse::ok($ver->execute($asamblea), message: 'Informe adjuntado.');
    }

    public function quitarAdjunto(AdjuntoDePuntoAction $adjunto, VerAsambleaAction $ver, int $asamblea, int $punto): JsonResponse
    {
        $adjunto->quitar($asamblea, $punto);

        return ApiResponse::ok($ver->execute($asamblea), message: 'Informe quitado.');
    }

    public function configuracion(ObtenerConfiguracionAction $configuracion): JsonResponse
    {
        return ApiResponse::ok($configuracion->execute());
    }

    public function guardarConfiguracion(Request $request, GuardarConfiguracionAction $guardar): JsonResponse
    {
        $d = $request->validate([
            'permite_virtual' => ['required', 'boolean'],
            'mora_cuenta_para_quorum' => ['required', 'boolean'],
            'voto_secreto' => ['required', 'boolean'],
            'porcentaje_reforma' => ['present', 'nullable', 'numeric', 'gt:50', 'max:100', 'decimal:0,2'],
        ], [
            'porcentaje_reforma.gt' => 'El porcentaje debe ser mayor que 50.', 'porcentaje_reforma.max' => 'El porcentaje no puede pasar de 100.', 'porcentaje_reforma.decimal' => 'Usa hasta dos decimales.',
        ]);

        return ApiResponse::ok($guardar->execute($d), message: 'Reglas de asamblea guardadas.');
    }

    /** @return array{titulo: string, tipo: string, modalidad: string, fecha_hora: string, lugar?: string|null, enlace_video?: string|null} */
    private function validar(Request $request): array
    {
        /** @var array{titulo: string, tipo: string, modalidad: string, fecha_hora: string, lugar?: string|null, enlace_video?: string|null} $d */
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:120'],
            'tipo' => ['required', Rule::in(Asamblea::TIPOS)],
            'modalidad' => ['required', Rule::in(Asamblea::MODALIDADES)],
            'fecha_hora' => ['required', 'date_format:Y-m-d\TH:i'],
            'lugar' => ['sometimes', 'nullable', 'string', 'max:160'],
            'enlace_video' => ['sometimes', 'nullable', 'url:https', 'max:300'],
        ], [
            'titulo.required' => 'Escribe el título de la asamblea.', 'titulo.min' => 'El título es muy corto.', 'titulo.max' => 'El título tiene máximo 120 caracteres.',
            'tipo.required' => 'Elige el tipo de asamblea.', 'tipo.in' => 'El tipo de asamblea no es válido.',
            'modalidad.required' => 'Elige la modalidad.', 'modalidad.in' => 'La modalidad no es válida.',
            'fecha_hora.required' => 'Indica la fecha y la hora.', 'fecha_hora.date_format' => 'La fecha y la hora no son válidas.',
            'lugar.max' => 'El lugar tiene máximo 160 caracteres.', 'enlace_video.url' => 'El enlace debe ser una dirección https.',
        ]);

        return $d;
    }

    /** @return array{titulo: string, descripcion?: string|null, tipo: string, mayoria?: string|null, umbral_pct?: float|string|null, base?: string|null} */
    private function validarPunto(Request $request): array
    {
        /** @var array{titulo: string, descripcion?: string|null, tipo: string, mayoria?: string|null, umbral_pct?: float|string|null, base?: string|null} $d */
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:160'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::in(AsambleaPunto::TIPOS)],
            'mayoria' => ['sometimes', 'nullable', Rule::in(['simple', 'calificada'])],
            'umbral_pct' => ['sometimes', 'nullable', 'numeric', 'gt:50', 'max:100', 'decimal:0,2'],
            'base' => ['sometimes', 'nullable', Rule::in(['presentes', 'total'])],
        ], [
            'titulo.required' => 'Escribe el punto.', 'titulo.min' => 'El punto es muy corto.', 'titulo.max' => 'El punto tiene máximo 160 caracteres.',
            'descripcion.max' => 'La descripción tiene máximo 2000 caracteres.', 'tipo.required' => 'Elige el tipo de punto.', 'tipo.in' => 'El tipo de punto no es válido.',
            'umbral_pct.gt' => 'El porcentaje debe ser mayor que 50.', 'umbral_pct.max' => 'El porcentaje no puede pasar de 100.', 'umbral_pct.decimal' => 'Usa hasta dos decimales.',
        ]);

        return $d;
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    public function convocar(Request $request, ConvocarAsambleaAction $convocar, VerAsambleaAction $ver, int $asamblea): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $convocar->execute($user, $asamblea);

        return ApiResponse::ok($ver->execute($asamblea), message: 'Asamblea convocada. Se avisó a los residentes.');
    }

    public function cancelar(Request $request, CancelarAsambleaAction $cancelar, VerAsambleaAction $ver, int $asamblea): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Indica el motivo.', 'motivo.min' => 'Explica el motivo con un poco más de detalle.', 'motivo.max' => 'El motivo no puede pasar de 300 caracteres.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $cancelar->execute($user, $asamblea, trim($d['motivo']));

        return ApiResponse::ok($ver->execute($asamblea), message: 'Asamblea cancelada. Se avisó a los residentes.');
    }

    public function convocatoria(VerConvocatoriaAction $ver, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($ver->execute($asamblea));
    }

    public function reenviarConvocatoria(ReenviarConvocatoriaAction $reenviar, int $asamblea): JsonResponse
    {
        $n = $reenviar->execute($asamblea);

        return ApiResponse::ok(['unidades' => $n], message: $n === 0 ? 'No hay unidades pendientes.' : "Convocatoria reenviada a {$n} unidades pendientes.");
    }

    public function constancia(ConstanciaDeConvocatoriaAction $constancia, int $asamblea): Response
    {
        $r = $constancia->execute($asamblea);

        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
