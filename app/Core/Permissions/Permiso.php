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

    public function modulo(): string
    {
        return match (true) {
            str_starts_with($this->value, 'plataforma.') => 'plataforma',
            default => 'nucleo',
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

    public function esDePlataforma(): bool
    {
        return $this->modulo() === 'plataforma';
    }
}
