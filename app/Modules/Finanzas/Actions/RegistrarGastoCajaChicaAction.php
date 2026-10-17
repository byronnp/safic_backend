<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Support\Dinero;
use App\Core\Tenancy\Calendario;
use App\Models\User;
use App\Modules\Finanzas\Models\CajaChica;
use App\Modules\Finanzas\Models\CajaChicaMovimiento;
use App\Modules\Finanzas\Services\PeriodoAbierto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: registrar un gasto menor de la caja chica. La foto del respaldo es obligatoria (factura,
 * nota de venta o recibo) y el monto no puede superar lo disponible del fondo.
 */
final class RegistrarGastoCajaChicaAction
{
    public function __construct(
        private readonly ArchivosCondominio $archivos,
        private readonly Calendario $calendario,
        private readonly PeriodoAbierto $periodoAbierto,
    ) {}

    public function execute(User $user, string $concepto, ?string $comercio, string $monto, string $fecha, UploadedFile $respaldo): CajaChicaMovimiento
    {
        if ($fecha > $this->calendario->hoy()) {
            throw new ApiException('FECHA_FUTURA', 'La fecha del gasto no puede ser futura.', 422);
        }
        $this->periodoAbierto->exigir($fecha);

        $ruta = $this->archivos->guardar($respaldo, 'caja-chica');

        try {
            return DB::transaction(function () use ($user, $concepto, $comercio, $monto, $fecha, $ruta): CajaChicaMovimiento {
                $caja = CajaChica::query()->lockForUpdate()->first()
                    ?? throw new ApiException('CAJA_SIN_CONFIGURAR', 'La caja chica aún no tiene fondo. La administración debe configurarla primero.', 409);

                $gastado = Dinero::suma(CajaChicaMovimiento::query()->where('estado', CajaChicaMovimiento::ABIERTO)->pluck('monto')->map(fn ($m) => (string) $m));
                $disponible = Dinero::centavos((string) $caja->fondo) - $gastado;
                if (Dinero::centavos($monto) > $disponible) {
                    throw new ApiException('FONDO_INSUFICIENTE', 'El gasto supera lo disponible en la caja chica ($ '.Dinero::texto($disponible).'). Pide que se reponga el fondo.', 422, ['disponible' => Dinero::texto($disponible)]);
                }

                return CajaChicaMovimiento::create([
                    'fecha' => $fecha, 'concepto' => $concepto, 'comercio' => $comercio, 'monto' => Dinero::texto(Dinero::centavos($monto)),
                    'respaldo_ruta' => $ruta, 'estado' => CajaChicaMovimiento::ABIERTO, 'registrado_por' => $user->id,
                ]);
            });
        } catch (\Throwable $e) {
            $this->archivos->eliminar($ruta);

            throw $e;
        }
    }
}
