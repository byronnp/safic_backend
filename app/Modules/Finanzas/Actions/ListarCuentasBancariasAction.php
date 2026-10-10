<?php

namespace App\Modules\Finanzas\Actions;

use App\Modules\Finanzas\Models\CuentaBancaria;

/**
 * Consulta: las cuentas bancarias del condominio (la principal primero).
 */
final class ListarCuentasBancariasAction
{
    /**
     * @return list<array{id: int, banco: string, tipo: string, numero: string, titular: string, es_principal: bool, activa: bool}>
     */
    public function execute(): array
    {
        return CuentaBancaria::query()
            ->orderByDesc('activa')->orderByDesc('es_principal')->orderBy('id')
            ->get()
            ->map(fn (CuentaBancaria $c): array => self::item($c))
            ->all();
    }

    public function uno(int $id): ?array
    {
        return collect($this->execute())->firstWhere('id', $id);
    }

    /**
     * @return array{id: int, banco: string, tipo: string, numero: string, titular: string, es_principal: bool, activa: bool}
     */
    public static function item(CuentaBancaria $c): array
    {
        return [
            'id' => $c->id, 'banco' => $c->banco, 'tipo' => $c->tipo, 'numero' => $c->numero,
            'titular' => $c->titular, 'es_principal' => $c->es_principal, 'activa' => $c->activa,
        ];
    }
}
