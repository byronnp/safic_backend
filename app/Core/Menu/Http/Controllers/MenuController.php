<?php

namespace App\Core\Menu\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Menu\Models\MenuItem;
use App\Core\Menu\Services\MenuService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menú del perfil del usuario. No exige permiso: el menú ya viene filtrado por
 * los permisos del usuario en el equipo activo.
 */
class MenuController
{
    public function __construct(private readonly MenuService $menu) {}

    /** GET /me/menu: menú en el condominio del header (middleware "condominio"). */
    public function condominio(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->menu->paraUsuario($this->usuario($request), MenuItem::AMBITO_CONDOMINIO));
    }

    /** GET /plataforma/me/menu: menú del panel de plataforma (middleware "plataforma"). */
    public function plataforma(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->menu->paraUsuario($this->usuario($request), MenuItem::AMBITO_PLATAFORMA));
    }

    private function usuario(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
