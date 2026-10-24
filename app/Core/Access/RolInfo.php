<?php

namespace App\Core\Access;

/** Un rol global tal como está en la base (tabla roles), con las reglas que el super admin no puede saltar. */
final class RolInfo
{
    /**
     * @param  list<string>  $obligatorios  Claves de permisos que el rol no puede perder
     */
    public function __construct(
        public readonly int $id,
        public readonly string $clave,
        public readonly string $etiqueta,
        public readonly string $ambito,
        public readonly bool $esCargo,
        public readonly ?bool $cuentaCupo,
        public readonly bool $asignable,
        public readonly bool $requiereVigencia,
        public readonly bool $exigeDobleFactor,
        public readonly bool $soloLectura,
        public readonly bool $sinAdministrativos,
        public readonly bool $sistema,
        public readonly int $orden,
        public readonly array $obligatorios,
    ) {}

    public function esDePlataforma(): bool
    {
        return $this->ambito === Catalogo::PLATAFORMA;
    }

    /** ¿El rol necesita este permiso para servir? */
    public function exige(string $permiso): bool
    {
        return in_array($permiso, $this->obligatorios, true);
    }

    /**
     * Reglas fijas: permisos que este rol nunca recibe, aunque el super admin lo intente. Devuelve el motivo, o null si se puede conceder.
     */
    public function motivoBloqueo(PermisoInfo $permiso): ?string
    {
        return match (true) {
            $this->soloLectura && $permiso->escritura => "{$this->etiqueta}: rol de solo lectura, no recibe permisos que modifican datos.",
            $this->sinAdministrativos && $permiso->administrativo => "{$this->etiqueta}: no recibe permisos administrativos.",
            $this->esDePlataforma() !== $permiso->esDePlataforma() => 'El permiso no corresponde al ámbito de este rol.',
            default => null,
        };
    }
}
