<?php

namespace App\Modules\Reservas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Amenidades\Models\CondominioAmenidad;
use App\Modules\Finanzas\Actions\CargosDeReservaAction;
use App\Modules\Reservas\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso de la administración: decidir qué pasa con los daños que el guardia reportó al recibir el área. Tres decisiones:
 * - sin cargo: no se cobra nada y la garantía se devuelve completa (a mano);
 * - descontar de la garantía: el daño (hasta el monto de la garantía) se queda de la garantía y el resto se devuelve (a mano);
 * - cobrar a la cuenta: se crea una cuota extraordinaria en la cuenta de la unidad (el único movimiento que hace el sistema).
 * Se decide una sola vez. Devolver dinero no lo hace el sistema: queda como «por devolver» para hacerlo aparte.
 */
final class ResolverDanosDeReservaAction
{
    /** Días que la unidad tiene para pagar un cobro por daños. */
    public const DIAS_PARA_PAGAR = 10;

    public function __construct(private readonly CargosDeReservaAction $cargos, private readonly Calendario $calendario) {}

    public function execute(User $admin, int $reservaId, string $decision, ?string $monto, ?string $nota): Reserva
    {
        return DB::transaction(function () use ($admin, $reservaId, $decision, $monto, $nota): Reserva {
            $r = Reserva::query()->lockForUpdate()->findOrFail($reservaId);
            if ($r->danos === null) {
                throw new ApiException('RESERVA_SIN_DANOS', 'Esta reserva no tiene daños reportados.', 404);
            }
            if ($r->danos_decision !== null) {
                throw new ApiException('DANOS_YA_RESUELTOS', 'Ya se decidió qué hacer con estos daños.', 409);
            }

            $centavos = $decision === Reserva::DANOS_SIN_CARGO ? 0 : Dinero::centavos((string) $monto);
            if ($decision !== Reserva::DANOS_SIN_CARGO && $centavos <= 0) {
                throw new ApiException('MONTO_INVALIDO', 'Indica el valor del daño.', 422);
            }
            if ($decision === Reserva::DANOS_DESCONTAR_GARANTIA && $centavos > Dinero::centavos((string) $r->garantia)) {
                throw new ApiException('MONTO_SUPERA_GARANTIA', 'El valor del daño supera la garantía: cóbralo a la cuenta de la unidad o descuenta solo hasta la garantía.', 422);
            }

            $cuotaId = null;
            if ($decision === Reserva::DANOS_COBRAR_CUENTA) {
                $area = (string) (CondominioAmenidad::query()->whereKey($r->amenidad_id)->value('nombre') ?? 'Área común');
                $vence = CarbonImmutable::parse($this->calendario->hoy())->addDays(self::DIAS_PARA_PAGAR)->toDateString();
                $cuotaId = $this->cargos->cobrar($r->unidad_id, "Daños {$area} · reserva {$r->id}", Dinero::texto($centavos), $vence);
            }

            $r->forceFill([
                'danos_decision' => $decision, 'danos_monto' => $centavos === 0 ? null : Dinero::texto($centavos), 'danos_cuota_id' => $cuotaId,
                'danos_nota' => $nota, 'danos_resuelta_por' => $admin->id, 'danos_resuelta_en' => now(),
            ])->save();

            return $r;
        });
    }
}
