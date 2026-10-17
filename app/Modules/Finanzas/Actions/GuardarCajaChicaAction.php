<?php

namespace App\Modules\Finanzas\Actions;

use App\Core\Http\Exceptions\ApiException;
use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\CajaChica;
use App\Modules\Finanzas\Models\CajaChicaMovimiento;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: fijar el fondo de la caja chica y quién responde por él. El fondo no puede quedar
 * por debajo de lo ya gastado desde la última reposición.
 */
final class GuardarCajaChicaAction
{
    public function execute(string $fondo, string $responsable): CajaChica
    {
        return DB::transaction(function () use ($fondo, $responsable): CajaChica {
            $gastado = Dinero::suma(CajaChicaMovimiento::query()->where('estado', CajaChicaMovimiento::ABIERTO)->lockForUpdate()->pluck('monto')->map(fn ($m) => (string) $m));
            if (Dinero::centavos($fondo) < $gastado) {
                throw new ApiException('FONDO_MENOR_AL_GASTO', 'El fondo no puede ser menor a lo ya gastado desde la última reposición ($ '.Dinero::texto($gastado).').', 422);
            }

            $caja = CajaChica::query()->lockForUpdate()->first() ?? new CajaChica;
            $caja->fill(['fondo' => Dinero::texto(Dinero::centavos($fondo)), 'responsable_nombre' => $responsable])->save();

            return $caja;
        });
    }
}
