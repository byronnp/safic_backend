<?php

namespace App\Modules\Plataforma\Enums;

/**
 * Tipos de condominio (columna condominios.tipo).
 */
enum TipoCondominio: string
{
    case Conjunto = 'conjunto';
    case Edificio = 'edificio';
    case Urbanizacion = 'urbanizacion';
    case Mixto = 'mixto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Conjunto => 'Conjunto',
            self::Edificio => 'Edificio',
            self::Urbanizacion => 'Urbanización',
            self::Mixto => 'Mixto',
        };
    }
}
