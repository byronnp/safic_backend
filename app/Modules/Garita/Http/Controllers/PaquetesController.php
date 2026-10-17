<?php

namespace App\Modules\Garita\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Garita\Actions\EntregarPaqueteAction;
use App\Modules\Garita\Actions\ListarMisPaquetesAction;
use App\Modules\Garita\Actions\ListarPaquetesGaritaAction;
use App\Modules\Garita\Actions\RecibirPaqueteAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paquetería: el guardia recibe y entrega; el residente ve los de su unidad.
 */
class PaquetesController
{
    public function index(ListarPaquetesGaritaAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function store(Request $request, RecibirPaqueteAction $recibir, ListarPaquetesGaritaAction $listar): JsonResponse
    {
        $d = $request->validate([
            'unidad_codigo' => ['required', 'string', 'max:20'],
            'empresa' => ['required', 'string', 'min:2', 'max:80'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:60'],
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
        ], [
            'unidad_codigo.required' => 'Indica la unidad.',
            'empresa.required' => 'Escribe la empresa o quién lo envía.',
            'foto.required' => 'La foto del paquete es obligatoria.',
            'foto.mimes' => 'La foto debe ser JPG o PNG.',
            'foto.max' => 'La foto pesa más de 5 MB.',
        ]);

        $p = $recibir->execute($this->usuario($request), $d['unidad_codigo'], trim($d['empresa']), isset($d['descripcion']) ? trim($d['descripcion']) : null, $request->file('foto'));

        return ApiResponse::created(['id' => $p->id], message: 'Paquete recibido. Se avisó a la unidad.');
    }

    public function entregar(Request $request, EntregarPaqueteAction $entregar, int $paquete): JsonResponse
    {
        $d = $request->validate([
            'ocupante_id' => ['sometimes', 'nullable', 'integer'],
            'autorizado' => ['sometimes', 'nullable', 'string', 'min:3', 'max:120'],
            'firma' => ['required', 'file', 'mimes:png', 'mimetypes:image/png', 'max:512'],
        ], [
            'autorizado.min' => 'El nombre de quien retira es muy corto.',
            'firma.required' => 'Falta la firma de quien retira.',
            'firma.mimes' => 'La firma debe ser una imagen PNG.',
            'firma.max' => 'La firma es demasiado grande.',
        ]);

        $entregar->execute($this->usuario($request), $paquete, isset($d['ocupante_id']) ? (int) $d['ocupante_id'] : null, $d['autorizado'] ?? null, $request->file('firma'));

        return ApiResponse::ok(null, message: 'Paquete entregado.');
    }

    public function mios(Request $request, ListarMisPaquetesAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->usuario($request)));
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
