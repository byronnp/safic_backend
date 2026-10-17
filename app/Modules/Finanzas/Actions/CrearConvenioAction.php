<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\ConvenioPago;
use App\Modules\Finanzas\Services\MorosidadService;
use App\Modules\Unidades\Actions\UnidadEstadoCuentaAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: acordar con una unidad que pagará su deuda vencida en cuotas mensuales. El total es
 * lo vencido al firmar; los pagos aprobados desde hoy cubren las cuotas en orden. Un solo convenio
 * activo por unidad. Firmarlo levanta la restricción de áreas comunes si la había.
 */
final class CrearConvenioAction
{
    public function __construct(
        private readonly MorosidadService $morosidad,
        private readonly UnidadEstadoCuentaAction $unidad,
        private readonly Calendario $calendario,
    ) {}

    public function execute(User $user, int $unidadId, int $cuotas, string $primeraCuotaEl): ConvenioPago
    {
        $this->unidad->execute($unidadId) ?? throw new ApiException('UNIDAD_NO_ENCONTRADA', 'Esa unidad no existe.', 404);

        $hoy = $this->calendario->hoy();
        if ($primeraCuotaEl < $hoy) {
            throw new ApiException('FECHA_PASADA', 'La primera cuota no puede ser una fecha pasada.', 422);
        }

        try {
            return DB::transaction(function () use ($user, $unidadId, $cuotas, $primeraCuotaEl, $hoy): ConvenioPago {
                $deuda = $this->morosidad->deudas([$unidadId])[$unidadId]
                    ?? throw new ApiException('SIN_DEUDA_VENCIDA', 'Esta unidad no tiene cuotas vencidas: no hay nada que acordar.', 409);

                $convenio = ConvenioPago::create([
                    'unidad_id' => $unidadId, 'monto_total' => Dinero::texto($deuda['saldo']), 'cuotas' => $cuotas,
                    'desde' => $hoy, 'primera_cuota_el' => $primeraCuotaEl, 'estado' => ConvenioPago::ACTIVO, 'creado_por' => $user->id,
                ]);

                $this->morosidad->reevaluar($unidadId);

                return $convenio;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('CONVENIO_ACTIVO', 'Esta unidad ya tiene un convenio activo. Cancélalo antes de firmar otro.', 409);
        }
    }
}
