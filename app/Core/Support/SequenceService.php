<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Números consecutivos por condominio (recibos, actas…), sin repetir ni saltar. Un solo
 * INSERT … ON CONFLICT DO UPDATE incrementa y devuelve el número: PostgreSQL bloquea la fila
 * mientras dura la transacción, así dos peticiones a la vez reciben números distintos. Si la
 * transacción se revierte, el número también (no queda un hueco).
 */
final class SequenceService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function siguiente(string $nombre): int
    {
        $fila = DB::selectOne(
            'INSERT INTO secuencias (condominio_id, nombre, ultimo, created_at, updated_at)
             VALUES (?, ?, 1, now(), now())
             ON CONFLICT (condominio_id, nombre) DO UPDATE SET ultimo = secuencias.ultimo + 1, updated_at = now()
             RETURNING ultimo',
            [$this->tenant->require(), $nombre],
        );

        return (int) $fila->ultimo;
    }
}
