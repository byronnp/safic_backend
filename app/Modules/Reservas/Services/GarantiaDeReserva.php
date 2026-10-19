<?php

namespace App\Modules\Reservas\Services;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\Cuota;
use App\Modules\Reservas\Models\Reserva;
use Illuminate\Support\Collection;

/**
 * Reglas de la garantía de una reserva ya recibida. La garantía se cobró (y pagó) como una cuota aparte; al terminar el uso:
 * - sin daños: se devuelve completa;
 * - con daños: según la decisión de la administración (ver ResolverDanosDeReservaAction): sin cargo o cobrar a la cuenta la
 *   devuelven completa, descontar del daño devuelve el resto, y mientras no se decida no se sabe cuánto.
 * Solo cuenta si la garantía está pagada: lo que nadie pagó no hay que devolverlo.
 */
final class GarantiaDeReserva
{
    public const ESPERANDO_DECISION = 'esperando_decision';

    public const POR_DEVOLVER = 'por_devolver';

    /** El daño consumió toda la garantía: no hay nada que devolver. */
    public const SIN_DEVOLUCION = 'sin_devolucion';

    public const DEVUELTA = 'devuelta';

    /**
     * Qué pasa con la garantía de una reserva, o null si no hay nada que gestionar (sin garantía, sin pagar o sin recibir).
     *
     * @return array{estado: string, monto: int}|null monto en centavos: lo que hay que devolver (o lo ya devuelto)
     */
    public function de(Reserva $r, bool $garantiaPagada): ?array
    {
        $garantia = Dinero::centavos((string) $r->garantia);
        if ($r->estado !== Reserva::FINALIZADA || $garantia <= 0 || ! $garantiaPagada) {
            return null;
        }

        if ($r->garantia_devuelta_en !== null) {
            return ['estado' => self::DEVUELTA, 'monto' => Dinero::centavos((string) $r->garantia_devuelta_monto)];
        }
        if ($r->danos !== null && $r->danos_decision === null) {
            return ['estado' => self::ESPERANDO_DECISION, 'monto' => 0];
        }

        $descuento = $r->danos_decision === Reserva::DANOS_DESCONTAR_GARANTIA ? Dinero::centavos((string) $r->danos_monto) : 0;
        $monto = max(0, $garantia - $descuento);

        return ['estado' => $monto > 0 ? self::POR_DEVOLVER : self::SIN_DEVOLUCION, 'monto' => $monto];
    }

    /**
     * Cuáles de esas reservas tienen la garantía pagada por completo (una sola consulta).
     *
     * @param  Collection<int, Reserva>  $reservas
     * @return array<int, bool> id de reserva → pagada
     */
    public function pagadas(Collection $reservas): array
    {
        $pagadas = Cuota::query()->whereIn('id', $reservas->pluck('cuota_garantia_id')->filter())->whereColumn('pagado', '>=', 'monto')->pluck('id')->flip();

        return $reservas->mapWithKeys(fn (Reserva $r): array => [$r->id => $r->cuota_garantia_id !== null && $pagadas->has($r->cuota_garantia_id)])->all();
    }

    public function pagada(Reserva $r): bool
    {
        return $this->pagadas(collect([$r]))[$r->id];
    }
}
