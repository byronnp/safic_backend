<?php

namespace App\Core\Tenancy;

use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Tercera barrera de aislamiento: pasa el condominio activo a PostgreSQL para que
 * las políticas de Row Level Security filtren cada fila.
 *
 * Usa set_config(..., true), equivalente a SET LOCAL: el valor vive solo hasta el
 * fin de la transacción y nunca pasa a otra petición aunque la conexión se
 * reutilice (RDS Proxy o PgBouncer en modo transacción). Por eso exige una
 * transacción abierta: la abren el middleware "condominio" y TenantContext::run().
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

        if ($connection->transactionLevel() === 0) {
            // Sin transacción no queda ningún valor local que limpiar.
            if ($condominioId === null) {
                return;
            }

            throw new LogicException('El condominio de RLS solo se fija dentro de una transacción: usa el middleware "condominio" o TenantContext::run().');
        }

        $connection->select('select set_config(?, ?, true)', [
            self::SETTING,
            $condominioId === null ? '' : (string) $condominioId,
        ]);
    }

    /**
     * Ejecuta $callback en una transacción (un savepoint si ya hay una abierta).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        return $this->db->connection()->transaction(fn () => $callback());
    }

    public function beginTransaction(): void
    {
        $this->db->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->connection()->commit();
    }

    public function rollBack(): void
    {
        $this->db->connection()->rollBack();
    }
}
