<?php

namespace App\Core\Permissions;

/**
 * Roles del sistema. Un solo catálogo para todos los condominios; solo el
 * super admin crea roles adicionales. Los cargos de directiva se asignan por
 * cargo (tabla cargos_directiva, fase 1), no directamente.
 */
enum Rol: string
{
    // Plataforma
    case SuperAdmin = 'super_admin';
    case Soporte = 'soporte';
    case Cobranza = 'cobranza';
    case ContadorPlataforma = 'contador_plataforma';

    // Condominio · sistema
    case Administrador = 'administrador';
    case Contador = 'contador';
    case Guardia = 'guardia';
    case Mantenimiento = 'mantenimiento';
    case Residente = 'residente';

    // Condominio · cargos de directiva
    case Presidente = 'presidente';
    case Vicepresidente = 'vicepresidente';
    case Secretario = 'secretario';
    case Tesorero = 'tesorero';

    /** Las asignaciones de plataforma usan este "condominio". */
    public const EQUIPO_PLATAFORMA = 0;

    public function esDePlataforma(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Soporte, self::Cobranza, self::ContadorPlataforma], true);
    }

    public function esCargo(): bool
    {
        return in_array($this, [self::Presidente, self::Vicepresidente, self::Secretario, self::Tesorero], true);
    }

    /**
     * Permisos por defecto (plantilla). El super admin los ajusta después desde el panel.
     *
     * @return list<Permiso>
     */
    public function permisosPorDefecto(): array
    {
        return match ($this) {
            self::SuperAdmin => Permiso::cases(),
            self::Soporte => [Permiso::PlataformaCondominios],
            self::Cobranza, self::ContadorPlataforma => [Permiso::PlataformaCobranza],
            self::Administrador => array_values(array_filter(Permiso::cases(), fn (Permiso $p) => ! $p->esDePlataforma())),
            self::Presidente, self::Vicepresidente, self::Secretario, self::Tesorero => [Permiso::UnidadesVer],
            self::Guardia => [Permiso::UnidadesVer, Permiso::GaritaDirectorio],
            self::Contador, self::Mantenimiento, self::Residente => [],
        };
    }
}
