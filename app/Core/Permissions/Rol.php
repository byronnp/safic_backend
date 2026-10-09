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

    /**
     * Perfiles que el administrador asigna directamente a un usuario. Los cargos de
     * directiva van por su tabla y "residente" nace con la persona, no se asigna aquí.
     *
     * @return list<self>
     */
    public static function asignables(): array
    {
        return [self::Administrador, self::Contador, self::Guardia, self::Mantenimiento];
    }

    /**
     * Cuenta para el límite de usuarios administrativos del plan. Presidente,
     * vicepresidente, secretario, guardia, mantenimiento y residentes no cuentan.
     */
    public function cuentaParaCupo(): bool
    {
        return in_array($this, [self::Administrador, self::Contador, self::Tesorero], true);
    }

    /** Acceso con fecha de vencimiento obligatoria (el rol se desactiva solo al llegar). */
    public function requiereVigencia(): bool
    {
        return $this === self::Contador;
    }

    /** @return list<string> */
    public static function nombresQueCuentanParaCupo(): array
    {
        return array_values(array_map(fn (self $rol) => $rol->value, array_filter(self::cases(), fn (self $rol) => $rol->cuentaParaCupo())));
    }

    /** Nombre del perfil para mostrar (correos y mensajes). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrador',
            self::Soporte => 'Soporte',
            self::Cobranza => 'Cobranza',
            self::ContadorPlataforma => 'Contador de plataforma',
            self::Administrador => 'Administrador',
            self::Contador => 'Contador',
            self::Guardia => 'Guardia',
            self::Mantenimiento => 'Mantenimiento',
            self::Residente => 'Residente',
            self::Presidente => 'Presidente',
            self::Vicepresidente => 'Vicepresidente',
            self::Secretario => 'Secretario',
            self::Tesorero => 'Tesorero',
        };
    }

    public function esCargo(): bool
    {
        return in_array($this, [self::Presidente, self::Vicepresidente, self::Secretario, self::Tesorero], true);
    }

    /**
     * Reglas fijas del código: permisos que este rol nunca recibe, aunque el super admin
     * lo intente. Devuelve el motivo, o null si se puede conceder.
     */
    public function motivoBloqueo(Permiso $permiso): ?string
    {
        return match (true) {
            $this === self::Contador && $permiso->esEscritura() => 'El contador es solo lectura.',
            $this === self::Residente && $permiso->esAdministrativo() => 'Un residente no recibe permisos administrativos.',
            $this === self::Guardia && $permiso->esAdministrativo() => 'El guardia no recibe permisos administrativos.',
            $this->esDePlataforma() !== $permiso->esDePlataforma() => 'El permiso no corresponde al ámbito de este rol.',
            default => null,
        };
    }

    /** Permiso sin el cual el rol deja de servir (el administrador se quedaría sin poder gestionar usuarios). */
    public function exige(Permiso $permiso): bool
    {
        return $this === self::Administrador && $permiso === Permiso::UsuariosGestionar;
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
