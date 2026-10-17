<?php

namespace App\Modules\Comunicacion\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Comunicacion\Actions\AlcanceDeAnuncioAction;
use App\Modules\Comunicacion\Actions\ConstanciaAnuncioAction;
use App\Modules\Comunicacion\Actions\ListarAnunciosAction;
use App\Modules\Comunicacion\Actions\ListarMisAvisosAction;
use App\Modules\Comunicacion\Actions\MarcarAvisoAction;
use App\Modules\Comunicacion\Actions\PublicarAnuncioAction;
use App\Modules\Comunicacion\Actions\ReenviarAnuncioAction;
use App\Modules\Comunicacion\Models\Anuncio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Anuncios: la administración los publica y sigue su lectura; el residente los lee y confirma.
 */
class AnunciosController
{
    public function index(ListarAnunciosAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function alcance(Request $request, AlcanceDeAnuncioAction $alcance): JsonResponse
    {
        $d = $request->validate([
            'destino_tipo' => ['required', 'in:'.Anuncio::TODOS.','.Anuncio::BLOQUE.','.Anuncio::UNIDADES],
            'bloque_id' => ['required_if:destino_tipo,bloque', 'nullable', 'integer'],
            'unidades' => ['required_if:destino_tipo,unidades', 'nullable', 'array', 'max:300'],
            'unidades.*' => ['string', 'max:20'],
        ]);

        return ApiResponse::ok($alcance->execute($d['destino_tipo'], isset($d['bloque_id']) ? (int) $d['bloque_id'] : null, $d['unidades'] ?? null));
    }

    public function store(Request $request, PublicarAnuncioAction $publicar): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:120'],
            'mensaje' => ['required', 'string', 'min:3', 'max:4000'],
            'destino_tipo' => ['required', 'in:'.Anuncio::TODOS.','.Anuncio::BLOQUE.','.Anuncio::UNIDADES],
            'bloque_id' => ['required_if:destino_tipo,bloque', 'nullable', 'integer'],
            'unidades' => ['required_if:destino_tipo,unidades', 'nullable', 'array', 'max:300'],
            'unidades.*' => ['string', 'max:20'],
            'fijar' => ['sometimes', 'boolean'],
            'confirmacion' => ['sometimes', 'boolean'],
            'expira' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'adjunto' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ], [
            'titulo.required' => 'Escribe el título del anuncio.',
            'titulo.min' => 'El título es muy corto.',
            'mensaje.required' => 'Escribe el mensaje del anuncio.',
            'mensaje.max' => 'El mensaje tiene máximo 4000 caracteres.',
            'bloque_id.required_if' => 'Elige el bloque.',
            'unidades.required_if' => 'Indica las unidades.',
            'expira.date_format' => 'La fecha no es válida.',
            'adjunto.mimes' => 'El adjunto debe ser un PDF o una imagen JPG o PNG.',
            'adjunto.max' => 'El adjunto pesa más de 5 MB.',
        ]);

        $a = $publicar->execute($this->usuario($request), [
            'titulo' => trim($d['titulo']), 'mensaje' => trim($d['mensaje']), 'destino_tipo' => $d['destino_tipo'],
            'bloque_id' => isset($d['bloque_id']) ? (int) $d['bloque_id'] : null, 'unidades' => $d['unidades'] ?? null,
            'fijar' => (bool) ($d['fijar'] ?? false), 'confirmacion' => (bool) ($d['confirmacion'] ?? false), 'expira' => $d['expira'] ?? null,
        ], $request->file('adjunto'));

        return ApiResponse::created(['id' => $a->id], message: 'Anuncio publicado. Se avisó a los residentes.');
    }

    public function reenviar(ReenviarAnuncioAction $reenviar, int $anuncio): JsonResponse
    {
        $n = $reenviar->execute($anuncio);

        return ApiResponse::ok(['unidades' => $n], message: $n === 0 ? 'No hay unidades pendientes.' : "Anuncio reenviado a {$n} unidades pendientes.");
    }

    public function constancia(ConstanciaAnuncioAction $constancia, int $anuncio): Response
    {
        $r = $constancia->execute($anuncio);

        return response($r['contenido'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$r['nombre'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function mios(Request $request, ListarMisAvisosAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    public function leer(Request $request, MarcarAvisoAction $marcar, int $anuncio): JsonResponse
    {
        $marcar->leer($this->usuario($request), $anuncio);

        return ApiResponse::ok(null, message: 'Aviso leído.');
    }

    public function confirmar(Request $request, MarcarAvisoAction $marcar, int $anuncio): JsonResponse
    {
        $marcar->confirmar($this->usuario($request), $anuncio);

        return ApiResponse::ok(null, message: 'Lectura confirmada. Gracias.');
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
