<?php

namespace App\Modules\Usuarios\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Usuarios\Models\SolicitudRol;
use Illuminate\Support\Facades\DB;

/**
 * Consulta pública del módulo Usuarios para el panel de plataforma: los pedidos de rol
 * nuevo que los condominios aún no han atendido.
 *
 * Las solicitudes tienen RLS y una petición de plataforma no fija condominio, así que se
 * recorre cada condominio en su propio contexto (nunca se debilita la política).
 */
final class ListarSolicitudesRolPendientesAction
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return list<array{id: int, condominio_id: int, condominio: string, nombre: string, descripcion: string, creada: string|null}>
     */
    public function execute(): array
    {
        $pendientes = [];

        foreach (DB::table('condominios')->orderBy('nombre')->get(['id', 'nombre']) as $condominio) {
            $solicitudes = $this->tenant->run(
                (int) $condominio->id,
                fn () => SolicitudRol::query()->where('estado', 'pendiente')->orderBy('created_at')->get(),
            );

            foreach ($solicitudes as $s) {
                $pendientes[] = [
                    'id' => $s->id,
                    'condominio_id' => (int) $condominio->id,
                    'condominio' => (string) $condominio->nombre,
                    'nombre' => $s->nombre,
                    'descripcion' => $s->descripcion,
                    'creada' => $s->created_at?->toIso8601String(),
                ];
            }
        }

        return $pendientes;
    }
}
