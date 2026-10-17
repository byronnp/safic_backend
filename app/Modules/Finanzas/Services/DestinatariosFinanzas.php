<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Permissions\Rol;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A quién se avisa de lo que pasa con el dinero del condominio: la administración y la tesorería.
 */
final class DestinatariosFinanzas
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<string> */
    public function correos(): array
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('mr.condominio_id', $this->tenant->require())
            ->whereIn('r.name', [Rol::Administrador->value, Rol::Tesorero->value])
            ->where('u.activo', true)->whereNotNull('u.email')
            ->distinct()->pluck('u.email')->all();
    }
}
