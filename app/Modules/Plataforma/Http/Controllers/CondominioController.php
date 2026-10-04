<?php

namespace App\Modules\Plataforma\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Permissions\Rol;
use App\Models\User;
use App\Modules\Plataforma\Actions\CrearCondominioAction;
use App\Modules\Plataforma\Http\Requests\CrearCondominioRequest;
use App\Modules\Plataforma\Http\Resources\CondominioPlataformaResource;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Condominios vistos por el panel de plataforma (todos los clientes).
 * Tabla de plataforma: no usa el contexto de condominio.
 */
class CondominioController
{
    public function index(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('buscar', ''));
        $porPagina = min(max((int) $request->query('por_pagina', 24), 1), 100);

        $pagina = Condominio::query()
            ->with(['plan', 'provincia', 'canton', 'parroquia'])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'ilike', '%'.addcslashes($buscar, '%_\\').'%')
                ->orWhere('ruc', 'like', addcslashes($buscar, '%_\\').'%')
                ->orWhere('codigo', 'ilike', addcslashes($buscar, '%_\\').'%')))
            ->orderBy('nombre')
            ->paginate($porPagina);

        $this->cargarAdministradores($pagina->getCollection());

        return ApiResponse::paginated($pagina, CondominioPlataformaResource::class);
    }

    public function show(Condominio $condominio): JsonResponse
    {
        $condominio->load(['plan', 'provincia', 'canton', 'parroquia']);
        $this->cargarAdministradores(new Collection([$condominio]));

        return ApiResponse::ok(new CondominioPlataformaResource($condominio));
    }

    public function store(CrearCondominioRequest $request, CrearCondominioAction $crear): JsonResponse
    {
        /** @var User $superAdmin */
        $superAdmin = $request->user();

        ['condominio' => $condominio, 'administrador_existente' => $existente] = $crear->execute($request->validated(), $superAdmin);

        $condominio->load(['plan', 'provincia', 'canton', 'parroquia']);
        $this->cargarAdministradores(new Collection([$condominio]));

        return ApiResponse::created(
            new CondominioPlataformaResource($condominio),
            meta: ['administrador_existente' => $existente, 'invitacion_enviada' => ! $existente],
            message: $existente
                ? 'Condominio creado. El administrador ya tenía cuenta: lo verá en su selector de condominios.'
                : 'Condominio creado. Enviamos la invitación al administrador.',
        );
    }

    /**
     * Administradores de cada condominio (rol administrador en su "equipo") en una sola
     * consulta, para no hacer una por tarjeta.
     *
     * @param  Collection<int, Condominio>  $condominios
     */
    private function cargarAdministradores(Collection $condominios): void
    {
        $ids = $condominios->modelKeys();
        if ($ids === []) {
            return;
        }

        $filas = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('roles.name', Rol::Administrador->value)
            ->whereIn('model_has_roles.condominio_id', $ids)
            ->orderBy('users.name')
            ->get(['model_has_roles.condominio_id', 'users.id', 'users.name', 'users.email', 'users.activo']);

        $porCondominio = $filas->groupBy('condominio_id');

        foreach ($condominios as $condominio) {
            $condominio->setAttribute('administradores', ($porCondominio->get($condominio->id) ?? collect())
                ->map(fn ($f) => [
                    'id' => (int) $f->id,
                    'nombre' => $f->name,
                    'email' => $f->email,
                    'estado' => $f->activo ? 'activo' : 'invitado',
                ])->values()->all());
        }
    }
}
