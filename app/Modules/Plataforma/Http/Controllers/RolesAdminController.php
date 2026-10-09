<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Modules\Plataforma\Actions\GuardarRolAction;
use App\Modules\Plataforma\Actions\ListarRolesAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Roles y permisos del sistema (plantillas globales) que administra el super admin.
 */
class RolesAdminController
{
    public function index(ListarRolesAdminAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute());
    }

    public function store(Request $request, GuardarRolAction $guardar, ListarRolesAdminAction $listar): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'permisos' => ['present', 'array'],
            'permisos.*' => ['string', 'max:60'],
        ], [
            'nombre.required' => 'Escribe el nombre del rol.',
            'nombre.min' => 'El nombre tiene mínimo 3 caracteres.',
            'nombre.max' => 'El nombre tiene máximo 60 caracteres.',
            'permisos.present' => 'Indica los permisos del rol.',
        ]);

        $rol = $guardar->crear($datos['nombre'], $datos['permisos'], (int) $request->user()?->id);

        return ApiResponse::created($this->uno($listar, $rol->name), message: 'Rol creado.');
    }

    public function permisos(Request $request, GuardarRolAction $guardar, ListarRolesAdminAction $listar, string $rol): JsonResponse
    {
        $datos = $request->validate([
            'permisos' => ['present', 'array'],
            'permisos.*' => ['string', 'max:60'],
        ], ['permisos.present' => 'Indica los permisos del rol.']);

        $guardar->permisos($rol, $datos['permisos'], (int) $request->user()?->id);

        return ApiResponse::ok($this->uno($listar, $rol), message: 'Permisos guardados. Rigen en todos los condominios.');
    }

    /**
     * @return array<string, mixed>
     */
    private function uno(ListarRolesAdminAction $listar, string $clave): array
    {
        return collect($listar->execute()['roles'])->firstWhere('clave', $clave) ?? [];
    }
}
