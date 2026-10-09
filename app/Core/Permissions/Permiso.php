<?php

namespace App\Core\Permissions;

/**
 * Catálogo de permisos. Nacen en el código (cada uno lo verifica una ruta o
 * policy) y se sincronizan a la base con RolesYPermisosSeeder.
 * Los módulos siguientes agregan aquí sus permisos.
 */
enum Permiso: string
{
    // Núcleo · unidades y residentes
    case UnidadesVer = 'unidades.ver';
    case UnidadesEditar = 'unidades.editar';
    case ResidentesVerDatos = 'residentes.ver_datos';
    case UsuariosGestionar = 'usuarios.gestionar';
    case AmenidadesGestionar = 'amenidades.gestionar';
    case CondominioEditar = 'condominio.editar';

    // Garita · el guardia ve nombre, unidad, teléfono y placas (sin cédula ni correo)
    case GaritaDirectorio = 'garita.directorio';

    // Plataforma (solo roles de plataforma, condominio_id = 0)
    case PlataformaCondominios = 'plataforma.condominios';
    case PlataformaRoles = 'plataforma.roles';
    case PlataformaCobranza = 'plataforma.cobranza';
    case PlataformaAuditoria = 'plataforma.auditoria';

    public function modulo(): string
    {
        return match (true) {
            str_starts_with($this->value, 'plataforma.') => 'plataforma',
            default => 'nucleo',
        };
    }

    /** Qué permite, en palabras de quien administra (pantalla Roles). */
    public function etiqueta(): string
    {
        return match ($this) {
            self::UnidadesVer => 'Ver unidades y residentes',
            self::UnidadesEditar => 'Crear y editar unidades',
            self::ResidentesVerDatos => 'Ver datos personales completos',
            self::UsuariosGestionar => 'Gestionar usuarios y directiva',
            self::AmenidadesGestionar => 'Gestionar amenidades',
            self::CondominioEditar => 'Editar datos y cobro del condominio',
            self::GaritaDirectorio => 'Consultar el directorio de garita',
            self::PlataformaCondominios => 'Gestionar condominios',
            self::PlataformaRoles => 'Gestionar roles y permisos',
            self::PlataformaCobranza => 'Gestionar la cobranza',
            self::PlataformaAuditoria => 'Consultar la bitácora de plataforma',
        };
    }

    /** Módulo con el que se agrupa en la pantalla Roles. */
    public function grupo(): string
    {
        return match (true) {
            $this === self::GaritaDirectorio => 'Garita',
            $this->esDePlataforma() => 'Plataforma',
            default => 'Núcleo',
        };
    }

    /** Cuenta para el límite de usuarios administrativos del plan. */
    public function esAdministrativo(): bool
    {
        return in_array($this, [
            self::UnidadesEditar, self::ResidentesVerDatos, self::UsuariosGestionar,
            self::AmenidadesGestionar, self::CondominioEditar,
        ], true);
    }

    /** Permiso que modifica datos (un rol de solo lectura no lo recibe). */
    public function esEscritura(): bool
    {
        return in_array($this, [self::UnidadesEditar, self::UsuariosGestionar, self::AmenidadesGestionar, self::CondominioEditar], true);
    }

    public function esDePlataforma(): bool
    {
        return $this->modulo() === 'plataforma';
    }
}
