<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Modules\Finanzas\Models\CuentaBancaria;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: agregar o editar una cuenta bancaria del condominio. Solo una cuenta activa es
 * la principal (la que ven los residentes): marcar una desmarca a la otra, la primera cuenta
 * nace principal y si la principal se desactiva pasa a otra activa. No se borran: se desactivan.
 */
final class GuardarCuentaBancariaAction
{
    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarCuentaBancariaRequest
     */
    public function crear(array $datos): CuentaBancaria
    {
        return DB::transaction(function () use ($datos): CuentaBancaria {
            $hayActivas = CuentaBancaria::query()->where('activa', true)->lockForUpdate()->first(['id']) !== null;
            $cuenta = new CuentaBancaria([
                'banco' => $datos['banco'], 'tipo' => $datos['tipo'], 'numero' => $datos['numero'], 'titular' => $datos['titular'],
                'activa' => true, 'es_principal' => false,
            ]);
            $cuenta->save();

            $this->marcarPrincipal($cuenta, ! $hayActivas || ($datos['es_principal'] ?? false));

            return $cuenta->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $datos  Solo lo que cambia
     */
    public function editar(int $id, array $datos): CuentaBancaria
    {
        return DB::transaction(function () use ($id, $datos): CuentaBancaria {
            $cuenta = CuentaBancaria::query()->lockForUpdate()->findOrFail($id);

            $cuenta->fill(array_intersect_key($datos, array_flip(['banco', 'tipo', 'numero', 'titular'])));

            if (array_key_exists('activa', $datos)) {
                $cuenta->activa = (bool) $datos['activa'];
            }
            $cuenta->save();

            if (! $cuenta->activa) {
                $cuenta->forceFill(['es_principal' => false])->save();
                $this->promoverSiFalta();
            } elseif (($datos['es_principal'] ?? false) === true) {
                $this->marcarPrincipal($cuenta, true);
            } elseif (($datos['es_principal'] ?? null) === false && $cuenta->es_principal) {
                throw new ApiException('CUENTA_PRINCIPAL', 'Marca otra cuenta como principal para quitarle este lugar.', 422);
            } else {
                $this->promoverSiFalta();
            }

            return $cuenta->refresh();
        });
    }

    private function marcarPrincipal(CuentaBancaria $cuenta, bool $principal): void
    {
        if (! $principal) {
            return;
        }

        CuentaBancaria::query()->whereKeyNot($cuenta->id)->where('es_principal', true)->update(['es_principal' => false]);
        $cuenta->forceFill(['es_principal' => true])->save();
    }

    /** Si quedan cuentas activas pero ninguna principal, la más antigua lo es. */
    private function promoverSiFalta(): void
    {
        if (CuentaBancaria::query()->where('activa', true)->where('es_principal', true)->exists()) {
            return;
        }

        $primera = CuentaBancaria::query()->where('activa', true)->orderBy('id')->first();
        $primera?->forceFill(['es_principal' => true])->save();
    }
}
