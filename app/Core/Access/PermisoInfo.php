<?php

namespace App\Core\Access;

/** Un permiso tal como está en la base (tabla permissions). */
final class PermisoInfo
{
    public function __construct(
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly string $grupo,
        public readonly string $ambito,
        public readonly bool $administrativo,
        public readonly bool $escritura,
    ) {}

    public function esDePlataforma(): bool
    {
        return $this->ambito === Catalogo::PLATAFORMA;
    }

    /** El módulo es lo que va antes del punto en la clave (`finanzas.ver` → `finanzas`). */
    public function modulo(): string
    {
        return explode('.', $this->clave)[0];
    }
}
