<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Models\User;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Finanzas\Models\ReglaConciliacion;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: decir que una línea del banco es una comisión, un interés u otro movimiento (sin
 * pago detrás). Opcionalmente guarda una regla («la descripción contiene…») que clasifica sola las
 * líneas parecidas, también las que ya están pendientes en el mismo estado de cuenta.
 */
final class ClasificarMovimientoAction
{
    public function __construct(private readonly PeriodoAbierto $periodoAbierto) {}

    public function execute(User $user, int $movimientoId, string $tipo, bool $guardarRegla, ?string $patron): MovimientoBancario
    {
        return DB::transaction(function () use ($user, $movimientoId, $tipo, $guardarRegla, $patron): MovimientoBancario {
            $m = MovimientoBancario::query()->lockForUpdate()->findOrFail($movimientoId);
            $this->periodoAbierto->exigir($m->fecha->toDateString());

            if ($m->estado === MovimientoBancario::CONCILIADO || $m->estado === MovimientoBancario::AUTOMATICO) {
                throw new ApiException('MOVIMIENTO_RESUELTO', 'Esta línea ya está conciliada. Desházla primero si quieres cambiarla.', 409);
            }

            $credito = Dinero::centavos($m->monto) > 0;
            if ($tipo === 'comision' && $credito) {
                throw new ApiException('DIRECCION_INVALIDA', 'Una comisión es un débito (dinero que sale).', 422);
            }
            if ($tipo === 'interes' && ! $credito) {
                throw new ApiException('DIRECCION_INVALIDA', 'Un interés es un crédito (dinero que entra).', 422);
            }

            $this->resolver($m, $tipo, $user);

            if ($guardarRegla) {
                $patron = $this->patron($m, $patron);
                try {
                    ReglaConciliacion::create(['patron' => $patron, 'tipo' => $tipo, 'creada_por' => $user->id]);
                } catch (UniqueConstraintViolationException) {
                    // Ya había una regla con ese texto: no se duplica
                }

                // Las demás líneas pendientes del mismo estado de cuenta que la cumplen
                $pendientes = MovimientoBancario::query()->where('estado_cuenta_id', $m->estado_cuenta_id)
                    ->whereIn('estado', [MovimientoBancario::SUGERIDO, MovimientoBancario::SIN_IDENTIFICAR])->whereKeyNot($m->id)->get();
                foreach ($pendientes as $otra) {
                    $mismaDireccion = (Dinero::centavos($otra->monto) > 0) === $credito;
                    if ($mismaDireccion && str_contains(mb_strtoupper($otra->descripcion), mb_strtoupper($patron))) {
                        $otra->forceFill([
                            'estado' => MovimientoBancario::AUTOMATICO, 'tipo' => $tipo,
                            'propuesta_tipo' => null, 'propuesta_id' => null, 'propuesta_texto' => null,
                        ])->save();
                    }
                }
            }

            return $m;
        });
    }

    private function resolver(MovimientoBancario $m, string $tipo, User $user): void
    {
        $m->forceFill([
            'estado' => MovimientoBancario::CONCILIADO, 'tipo' => $tipo,
            'propuesta_tipo' => null, 'propuesta_id' => null, 'propuesta_texto' => null,
            'conciliado_por' => $user->id, 'conciliado_en' => now(),
        ])->save();
    }

    /** El texto de la regla: el pedido, o la descripción sin números (referencias, fechas). */
    private function patron(MovimientoBancario $m, ?string $pedido): string
    {
        $base = $pedido !== null && trim($pedido) !== '' ? $pedido : (preg_replace('/\b[\w-]*\d[\w-]*\b/u', '', $m->descripcion) ?? $m->descripcion);
        $patron = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $base) ?? ''));

        if (mb_strlen($patron) < 4) {
            throw new ApiException('REGLA_MUY_CORTA', 'El texto de la regla es muy corto: escribe al menos 4 letras.', 422);
        }

        return mb_substr($patron, 0, 80);
    }
}
