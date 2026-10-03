<?php

namespace App\Modules\Plataforma\Enums;

/**
 * Cómo se calcula la cuota de cada unidad (decidido el 3 oct 2026).
 * Se define en el alta del condominio y se cambia en Configuración › Cobro de cuotas.
 */
enum MetodoCobro: string
{
    /** Todas las unidades pagan el mismo valor. La unidad no pide alícuota ni monto. */
    case ValorGeneral = 'valor_general';

    /** Un valor por tipo de unidad (casa, departamento, local…). */
    case PorTipo = 'por_tipo';

    /** Cuota total del condominio repartida según la alícuota de cada unidad. */
    case PorAlicuota = 'por_alicuota';

    /** Cada unidad tiene su propio valor. */
    case PorUnidad = 'por_unidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ValorGeneral => 'Valor general',
            self::PorTipo => 'Por tipo de unidad',
            self::PorAlicuota => 'Por alícuota',
            self::PorUnidad => 'Por unidad',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::ValorGeneral => 'Todas las unidades pagan el mismo valor.',
            self::PorTipo => 'Un valor para cada tipo de unidad (casa, departamento, local).',
            self::PorAlicuota => 'La cuota total se reparte según la alícuota de cada unidad.',
            self::PorUnidad => 'Cada unidad tiene su propio valor.',
        };
    }
}
