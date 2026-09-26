<?php

namespace App\Core\Tenancy;

use Illuminate\Database\DatabaseManager;

/**
 * Tercera barrera de aislamiento: pasa el condominio activo a PostgreSQL para que
 * las políticas de Row Level Security filtren cada fila.
 *
 * Usa set_config(..., false) a nivel de sesión (no SET LOCAL) para que aplique a
 * todas las consultas de la petición aunque no haya transacción; el middleware y
 * TenantContext::clear() lo limpian al terminar.
 */
final class TenantDatabase
{
    public const SETTING = 'app.condominio_id';

    public function __construct(private readonly DatabaseManager $db) {}

    public function apply(?int $condominioId): void
    {
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select('select set_config(?, ?, false)', [
            self::SETTING,
            $condominioId === null ? '' : (string) $condominioId,
        ]);
    }
}
