<?php

namespace App\Core\Banking;

/**
 * Lo que se lee de un estado de cuenta del banco: sus líneas y, si el archivo las trae, los saldos.
 * Los montos son texto decimal con signo (crédito +, débito −).
 */
final class EstadoCuentaLeido
{
    /**
     * @param  list<array{fecha: string, descripcion: string, referencia: string|null, monto: string, saldo: string|null}>  $movimientos
     */
    public function __construct(
        public readonly array $movimientos,
        public readonly ?string $saldoInicial,
        public readonly ?string $saldoFinal,
    ) {}
}
