<?php

namespace App\Modules\Asambleas\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Validation\Rules\CedulaEc;
use App\Models\User;
use App\Modules\Asambleas\Actions\CargarPoderAction;
use App\Modules\Asambleas\Actions\ListarPoderesAction;
use App\Modules\Asambleas\Actions\MisAsambleasAction;
use App\Modules\Asambleas\Actions\ResolverPoderAction;
use App\Modules\Asambleas\Actions\RevocarPoderAction;
use App\Modules\Asambleas\Models\AsambleaPoder;
use App\Modules\Asambleas\Services\PresentadorDePoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Poderes de la asamblea (Art. 40): el propietario sube el poder o la carta, la administración lo valida.
 */
class PoderesController
{
    public function index(ListarPoderesAction $listar, int $asamblea): JsonResponse
    {
        return ApiResponse::ok($listar->execute($asamblea));
    }

    public function store(Request $request, CargarPoderAction $cargar, PresentadorDePoder $presentador, int $asamblea): JsonResponse
    {
        $d = $request->validate([
            'unidad_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(AsambleaPoder::TIPOS)],
            'representante_nombre' => ['required', 'string', 'min:3', 'max:120'],
            'representante_tipo_documento' => ['sometimes', Rule::in(['cedula', 'pasaporte'])],
            'representante_documento' => ['required', 'string', 'min:5', 'max:20'],
            'archivo' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240'],
        ], [
            'unidad_id.required' => 'Elige la unidad.', 'tipo.required' => 'Indica si es un poder notarial o una carta.', 'tipo.in' => 'El tipo no es válido.',
            'representante_nombre.required' => 'Escribe el nombre del representante.', 'representante_nombre.min' => 'El nombre es muy corto.',
            'representante_documento.required' => 'Escribe la cédula del representante.',
            'archivo.required' => 'Sube el poder o la carta en PDF.', 'archivo.mimes' => 'El poder debe ser un PDF.', 'archivo.max' => 'El PDF pesa más de 10 MB.',
        ]);
        $tipoDocumento = $d['representante_tipo_documento'] ?? 'cedula';
        if ($tipoDocumento === 'cedula' && ! CedulaEc::esValida($d['representante_documento'])) {
            throw ValidationException::withMessages(['representante_documento' => 'La cédula no es válida.']);
        }
        /** @var User $user */
        $user = $request->user();
        $poder = $cargar->execute($user, $asamblea, [
            'unidad_id' => (int) $d['unidad_id'], 'tipo' => $d['tipo'], 'representante_nombre' => trim($d['representante_nombre']),
            'representante_tipo_documento' => $tipoDocumento, 'representante_documento' => $d['representante_documento'],
        ], $request->file('archivo'));

        return ApiResponse::created($presentador->uno($poder), message: 'Poder cargado. La administración lo revisará.');
    }

    public function validar(Request $request, ResolverPoderAction $resolver, PresentadorDePoder $presentador, int $poder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($presentador->uno($resolver->validar($user, $poder)), message: 'Poder validado.');
    }

    public function rechazar(Request $request, ResolverPoderAction $resolver, PresentadorDePoder $presentador, int $poder): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']], [
            'motivo.required' => 'Indica el motivo.', 'motivo.min' => 'Explica el motivo con un poco más de detalle.', 'motivo.max' => 'El motivo no puede pasar de 300 caracteres.',
        ]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($presentador->uno($resolver->rechazar($user, $poder, trim($d['motivo']))), message: 'Poder rechazado.');
    }

    public function destroy(Request $request, RevocarPoderAction $revocar, PresentadorDePoder $presentador, int $poder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($presentador->uno($revocar->execute($user, $poder)), message: 'Poder retirado.');
    }

    public function mias(Request $request, MisAsambleasAction $mias): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::ok($mias->execute($user));
    }
}
