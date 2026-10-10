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

    // Finanzas
    case FinanzasVer = 'finanzas.ver';
    case CuotasEmitir = 'cuotas.emitir';
    case CuentaPropia = 'cuenta.propia';
    case PagosAprobar = 'pagos.aprobar';
    case GastosRegistrar = 'gastos.registrar';
    case ProveedoresEditar = 'proveedores.editar';
    case GastosAprobarN1 = 'gastos.aprobar-n1';
    case GastosAprobarN2 = 'gastos.aprobar-n2';
    case GastosPagar = 'gastos.pagar';

    // Plataforma (solo roles de plataforma, condominio_id = 0)
    case PlataformaCondominios = 'plataforma.condominios';
    case PlataformaRoles = 'plataforma.roles';
    case PlataformaCobranza = 'plataforma.cobranza';
    case PlataformaAuditoria = 'plataforma.auditoria';

    public function modulo(): string
    {
        return match (true) {
            str_starts_with($this->value, 'plataforma.') => 'plataforma',
            str_starts_with($this->value, 'finanzas.'), str_starts_with($this->value, 'cuotas.'), str_starts_with($this->value, 'cuenta.'), str_starts_with($this->value, 'pagos.'), str_starts_with($this->value, 'gastos.'), str_starts_with($this->value, 'proveedores.') => 'finanzas',
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
            self::FinanzasVer => 'Ver finanzas y reportes',
            self::CuotasEmitir => 'Emitir las cuotas del mes',
            self::CuentaPropia => 'Ver y pagar la cuenta de su unidad',
            self::PagosAprobar => 'Aprobar pagos de residentes',
            self::GastosRegistrar => 'Registrar facturas de proveedores',
            self::ProveedoresEditar => 'Gestionar proveedores y sus cuentas',
            self::GastosAprobarN1 => 'Aprobar facturas (1.er nivel)',
            self::GastosAprobarN2 => 'Aprobar facturas grandes (2.º nivel)',
            self::GastosPagar => 'Pagar facturas a proveedores',
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
            $this->modulo() === 'finanzas' => 'Finanzas',
            $this->esDePlataforma() => 'Plataforma',
            default => 'Núcleo',
        };
    }

    /** Cuenta para el límite de usuarios administrativos del plan. */
    public function esAdministrativo(): bool
    {
        return in_array($this, [
            self::UnidadesEditar, self::ResidentesVerDatos, self::UsuariosGestionar,
            self::AmenidadesGestionar, self::CondominioEditar, self::CuotasEmitir, self::PagosAprobar,
            self::GastosRegistrar, self::ProveedoresEditar, self::GastosAprobarN1, self::GastosPagar,
        ], true);
    }

    /** Permiso que modifica datos (un rol de solo lectura no lo recibe). */
    public function esEscritura(): bool
    {
        return in_array($this, [self::UnidadesEditar, self::UsuariosGestionar, self::AmenidadesGestionar, self::CondominioEditar, self::CuotasEmitir, self::PagosAprobar, self::GastosRegistrar, self::ProveedoresEditar, self::GastosAprobarN1, self::GastosAprobarN2, self::GastosPagar], true);
    }

    public function esDePlataforma(): bool
    {
        return $this->modulo() === 'plataforma';
    }
}
