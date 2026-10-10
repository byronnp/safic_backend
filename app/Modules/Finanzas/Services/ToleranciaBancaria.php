<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\ConfiguracionCobro;

/**
 * Diferencia de centavos con el banco que no impide dar una cuota por pagada. La fija el cobro
 * del condominio; sin configuración son $0,50.
 */
final class ToleranciaBancaria
{
    public const POR_DEFECTO = '0.50';

    public function centavos(): int
    {
        return Dinero::centavos(ConfiguracionCobro::query()->value('tolerancia_bancaria') ?? self::POR_DEFECTO);
    }
}
