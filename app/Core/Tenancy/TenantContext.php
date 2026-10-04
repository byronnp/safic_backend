<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Exceptions\CondominioNoResueltoException;
use Throwable;

/**
 * Condominio activo de la petición o del job.
 *
 * Lo fija el middleware ResolveCondominio (HTTP) o TenantContext::run() (jobs,
 * comandos, seeders). Toda consulta a tablas con condominio_id depende de él.
 */
final class TenantContext
{
    private ?int $condominioId = null;

    public function __construct(private readonly TenantDatabase $database) {}

    public function set(int $condominioId): void
    {
        $this->condominioId = $condominioId;
        $this->database->apply($condominioId);
    }

    public function clear(): void
    {
        $this->condominioId = null;
        $this->database->apply(null);
    }

    public function id(): ?int
    {
        return $this->condominioId;
    }

    public function has(): bool
    {
        return $this->condominioId !== null;
    }

    /**
     * Devuelve el condominio activo o falla: nunca se escribe en una tabla de
     * condominio sin saber a cuál pertenece.
     */
    public function require(): int
    {
        return $this->condominioId ?? throw new CondominioNoResueltoException;
    }

    /**
     * Ejecuta $callback dentro de un condominio y restaura el anterior al terminar.
     * Corre en una transacción (RLS se fija con SET LOCAL): si falla, no queda
     * nada a medias.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(int $condominioId, callable $callback): mixed
    {
        $anterior = $this->condominioId;

        return $this->database->transaction(function () use ($condominioId, $anterior, $callback) {
            $this->set($condominioId);

            try {
                $resultado = $callback();
            } catch (Throwable $e) {
                // Si la transacción quedó abortada, restaurar en la base falla; el
                // rollback del savepoint devuelve RLS al valor anterior y aquí se
                // conserva el error original, que es el útil.
                try {
                    $this->restaurar($anterior);
                } catch (Throwable) {
                }

                throw $e;
            }

            $this->restaurar($anterior);

            return $resultado;
        });
    }

    private function restaurar(?int $anterior): void
    {
        $anterior === null ? $this->clear() : $this->set($anterior);
    }
}
