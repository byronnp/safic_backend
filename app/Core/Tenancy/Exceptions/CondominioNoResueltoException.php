<?php

namespace App\Core\Tenancy\Exceptions;

use LogicException;

/**
 * Error de programación: se intentó escribir en una tabla de condominio sin
 * condominio activo. Nunca debe llegar al usuario final.
 */
final class CondominioNoResueltoException extends LogicException
{
    public function __construct()
    {
        parent::__construct('No hay condominio activo. Usa el middleware "condominio" o TenantContext::run().');
    }
}
