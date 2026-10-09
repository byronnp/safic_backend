<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Menu\Models\MenuItem;
use App\Modules\Plataforma\Actions\GuardarMenuItemAction;
use App\Modules\Plataforma\Actions\ListarMenuSistemaAction;
use App\Modules\Plataforma\Actions\MoverMenuItemAction;
use App\Modules\Plataforma\Actions\VistaPreviaMenuAction;
use App\Modules\Plataforma\Http\Requests\GuardarMenuItemRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menú del sistema (catálogo global de pantallas del menú) que administra el super admin.
 * El cambio rige para todos los condominios al instante.
 */
class MenuSistemaController
{
    public function index(Request $request, ListarMenuSistemaAction $listar): JsonResponse
    {
        return ApiResponse::ok($listar->execute($this->ambito($request)));
    }

    public function store(GuardarMenuItemRequest $request, GuardarMenuItemAction $guardar, ListarMenuSistemaAction $listar): JsonResponse
    {
        $item = $guardar->crear($request->validated());

        return ApiResponse::created($this->uno($listar, $item), message: 'Ítem agregado al menú.');
    }

    public function update(GuardarMenuItemRequest $request, GuardarMenuItemAction $guardar, ListarMenuSistemaAction $listar, int $item): JsonResponse
    {
        $actualizado = $guardar->editar($item, $request->validated());

        return ApiResponse::ok($this->uno($listar, $actualizado), message: 'Cambios guardados.');
    }

    public function mover(Request $request, MoverMenuItemAction $mover, ListarMenuSistemaAction $listar, int $item): JsonResponse
    {
        $datos = $request->validate(['direccion' => ['required', Rule::in(['arriba', 'abajo'])]], [
            'direccion.required' => 'Indica si sube o baja.',
            'direccion.in' => 'Indica si sube o baja.',
        ]);

        $movido = $mover->execute($item, $datos['direccion']);

        return ApiResponse::ok($listar->execute($movido->ambito)['items'], message: 'Orden actualizado.');
    }

    public function vistaPrevia(Request $request, VistaPreviaMenuAction $vista): JsonResponse
    {
        $datos = $request->validate([
            'ambito' => ['required', Rule::in([MenuItem::AMBITO_CONDOMINIO, MenuItem::AMBITO_PLATAFORMA])],
            'perfil' => ['required', 'string', 'max:40'],
        ]);

        return ApiResponse::ok($vista->execute($datos['ambito'], $datos['perfil']));
    }

    private function ambito(Request $request): string
    {
        return $request->validate([
            'ambito' => ['required', Rule::in([MenuItem::AMBITO_CONDOMINIO, MenuItem::AMBITO_PLATAFORMA])],
        ], ['ambito.required' => 'Elige el menú (condominio o plataforma).', 'ambito.in' => 'Elige el menú (condominio o plataforma).'])['ambito'];
    }

    /**
     * @return array<string, mixed>
     */
    private function uno(ListarMenuSistemaAction $listar, MenuItem $item): array
    {
        return collect($listar->execute($item->ambito)['items'])->firstWhere('id', $item->id) ?? [];
    }
}
