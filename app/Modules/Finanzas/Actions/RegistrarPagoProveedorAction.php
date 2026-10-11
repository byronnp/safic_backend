<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Support\SequenceService;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\Gasto;
use App\Modules\Finanzas\Models\GastoAprobacion;
use App\Modules\Finanzas\Models\PagoProveedor;
use App\Modules\Finanzas\Models\PagoProveedorAplicacion;
use App\Modules\Finanzas\Models\Proveedor;
use App\Modules\Finanzas\Models\ProveedorCuenta;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar una transferencia hecha a un proveedor desde la banca en línea (la app
 * no mueve dinero). Solo facturas aprobadas del proveedor; se permiten abonos: el monto se aplica
 * a la factura más antigua primero y nunca supera el saldo elegido. Se paga a la cuenta vigente
 * del proveedor (un cambio en espera no cuenta). Quien aprobó una factura no la paga, salvo que
 * el condominio lo permita.
 */
final class RegistrarPagoProveedorAction
{
    public function __construct(
        private readonly SequenceService $secuencias,
        private readonly ArchivosCondominio $archivos,
        private readonly Calendario $calendario,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    /**
     * @param  list<int>  $gastoIds
     */
    public function execute(User $user, int $proveedorId, array $gastoIds, string $monto, int $cuentaBancariaId, string $fechaPago, string $referencia, ?UploadedFile $comprobante): PagoProveedor
    {
        if ($fechaPago > $this->calendario->hoy()) {
            throw new ApiException('FECHA_FUTURA', 'La fecha de la transferencia no puede ser futura.', 422);
        }

        $this->periodoAbierto->exigir($fechaPago);

        $ruta = $comprobante !== null ? $this->archivos->guardar($comprobante, 'pagos-proveedor') : null;

        try {
            return DB::transaction(fn (): PagoProveedor => $this->registrar($user, $proveedorId, array_values(array_unique($gastoIds)), Dinero::centavos($monto), $cuentaBancariaId, $fechaPago, trim($referencia), $ruta));
        } catch (UniqueConstraintViolationException) {
            $this->limpiar($ruta);

            throw new ApiException('REFERENCIA_DUPLICADA', 'Esa referencia del banco ya está registrada para este proveedor.', 409);
        } catch (\Throwable $e) {
            $this->limpiar($ruta);

            throw $e;
        }
    }

    /**
     * @param  list<int>  $gastoIds
     */
    private function registrar(User $user, int $proveedorId, array $gastoIds, int $monto, int $cuentaBancariaId, string $fechaPago, string $referencia, ?string $ruta): PagoProveedor
    {
        $proveedor = Proveedor::query()->lockForUpdate()->findOrFail($proveedorId);

        $cuentaProveedor = ProveedorCuenta::query()->where('proveedor_id', $proveedor->id)->where('estado', 'activa')->first()
            ?? throw new ApiException('SIN_CUENTA_PROVEEDOR', 'Este proveedor aún no tiene una cuenta para pagos. Regístrala primero.', 409);

        $cuentaOrigen = CuentaBancaria::query()->where('activa', true)->find($cuentaBancariaId)
            ?? throw new ApiException('CUENTA_ORIGEN_INVALIDA', 'Elige una cuenta bancaria del condominio.', 422);

        $facturas = Gasto::query()->whereIn('id', $gastoIds)->where('proveedor_id', $proveedor->id)
            ->orderBy('vence_el')->orderBy('id')->lockForUpdate()->get();

        if ($facturas->count() !== count($gastoIds)) {
            throw new ApiException('FACTURA_INVALIDA', 'Alguna factura no es de este proveedor.', 422);
        }
        if ($facturas->contains(fn (Gasto $g) => $g->estado !== 'aprobada')) {
            throw new ApiException('FACTURA_NO_APROBADA', 'Solo se pagan facturas aprobadas y con saldo.', 409);
        }

        if (! ConfiguracionFinanzas::vigente()->aprobador_puede_pagar
            && GastoAprobacion::query()->whereIn('gasto_id', $gastoIds)->where('user_id', $user->id)->where('decision', 'aprobada')->exists()) {
            throw new ApiException('APROBADOR_NO_PAGA', 'Quien aprobó una de estas facturas no puede pagarla.', 403);
        }

        $saldos = $facturas->mapWithKeys(fn (Gasto $g) => [$g->id => Dinero::centavos($g->total) - Dinero::centavos($g->pagado)]);
        if ($monto > $saldos->sum()) {
            throw new ApiException('MONTO_SUPERA_SALDO', 'El monto supera el saldo de las facturas elegidas.', 422, ['saldo' => Dinero::texto((int) $saldos->sum())]);
        }

        $pago = PagoProveedor::create([
            'numero' => $this->secuencias->siguiente('pagos_proveedor'),
            'proveedor_id' => $proveedor->id, 'proveedor_cuenta_id' => $cuentaProveedor->id, 'cuenta_bancaria_id' => $cuentaOrigen->id,
            'monto' => Dinero::texto($monto), 'fecha_pago' => $fechaPago, 'referencia' => $referencia,
            'comprobante_ruta' => $ruta, 'estado' => 'registrado', 'registrado_por' => $user->id,
        ]);

        $restante = $monto;
        foreach ($facturas as $g) {
            if ($restante <= 0) {
                break;
            }
            $aplicado = min($restante, $saldos[$g->id]);
            PagoProveedorAplicacion::create(['pago_proveedor_id' => $pago->id, 'gasto_id' => $g->id, 'monto' => Dinero::texto($aplicado)]);

            $g->pagado = Dinero::texto(Dinero::centavos($g->pagado) + $aplicado);
            if ($aplicado === $saldos[$g->id]) {
                $g->estado = 'pagada';
            }
            $g->save();
            $restante -= $aplicado;
        }

        return $pago;
    }

    private function limpiar(?string $ruta): void
    {
        if ($ruta !== null) {
            $this->archivos->eliminar($ruta);
        }
    }
}
