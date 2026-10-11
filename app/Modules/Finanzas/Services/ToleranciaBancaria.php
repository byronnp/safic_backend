<?php

namespace App\Modules\Finanzas\Services;

use App\Core\Support\Dinero;
use App\Modules\Finanzas\Models\ConfiguracionFinanzas;

/**
 * Diferencia de centavos con el banco que no impide dar una cuota por pagada. La fija el condominio
 * en sus reglas de dinero (ConfiguracionFinanzas); sin configuración son $0,50.
 */
final class ToleranciaBancaria
{
    public const POR_DEFECTO = ConfiguracionFinanzas::TOLERANCIA_POR_DEFECTO;

    public function centavos(): int
    {
        return Dinero::centavos(ConfiguracionFinanzas::vigente()->tolerancia_bancaria);
    }
}
