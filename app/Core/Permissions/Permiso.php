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
    case FinanzasExportar = 'finanzas.exportar';
    case CuotasEmitir = 'cuotas.emitir';
    case CuentaPropia = 'cuenta.propia';
    case PagosAprobar = 'pagos.aprobar';
    case GastosRegistrar = 'gastos.registrar';
    case ProveedoresEditar = 'proveedores.editar';
    case CajaChicaRegistrar = 'cajachica.registrar';
    case GastosAprobarN1 = 'gastos.aprobar-n1';
    case GastosAprobarN2 = 'gastos.aprobar-n2';
    case GastosPagar = 'gastos.pagar';
    case PeriodosCerrar = 'periodos.cerrar';
    case PeriodosReabrir = 'periodos.reabrir';
    case ConciliacionGestionar = 'conciliacion.gestionar';
    case MorosidadGestionar = 'morosidad.gestionar';

    // Áreas comunes · reglas, agenda y aprobación de reservas
    case ReservasGestionar = 'reservas.gestionar';
    case ReservasPropias = 'reservas.propias';
    case ReservasGarita = 'reservas.garita';

    // Garita · visitas autorizadas por el residente
    case VisitasPropias = 'visitas.propias';
    case GaritaRegistrar = 'garita.registrar';
    case GaritaBitacora = 'garita.bitacora';
    case PaquetesGestionar = 'paquetes.gestionar';
    case PaquetesPropios = 'paquetes.propios';

    // Comunicación · anuncios de la administración
    case AnunciosPublicar = 'anuncios.publicar';
    case AvisosPropios = 'avisos.propios';
    case IncidenciasGestionar = 'incidencias.gestionar';
    case IncidenciasReportar = 'incidencias.reportar';

    // Plataforma (solo roles de plataforma, condominio_id = 0)
    case PlataformaCondominios = 'plataforma.condominios';
    case PlataformaCondominiosEditar = 'plataforma.condominios-editar';
    case PlataformaRoles = 'plataforma.roles';
    case PlataformaCobranza = 'plataforma.cobranza';
    case PlataformaAuditoria = 'plataforma.auditoria';

    public function modulo(): string
    {
        return match (true) {
            str_starts_with($this->value, 'plataforma.') => 'plataforma',
            str_starts_with($this->value, 'reservas.') => 'reservas',
            str_starts_with($this->value, 'anuncios.'), str_starts_with($this->value, 'avisos.'), str_starts_with($this->value, 'incidencias.') => 'comunicacion',
            str_starts_with($this->value, 'visitas.'), str_starts_with($this->value, 'garita.'), str_starts_with($this->value, 'paquetes.') => 'garita',
            str_starts_with($this->value, 'finanzas.'), str_starts_with($this->value, 'cuotas.'), str_starts_with($this->value, 'cuenta.'), str_starts_with($this->value, 'pagos.'), str_starts_with($this->value, 'gastos.'), str_starts_with($this->value, 'periodos.'), str_starts_with($this->value, 'cajachica.'), str_starts_with($this->value, 'conciliacion.'), str_starts_with($this->value, 'morosidad.'), str_starts_with($this->value, 'proveedores.') => 'finanzas',
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
            self::FinanzasExportar => 'Exportar reportes a Excel',
            self::CuotasEmitir => 'Emitir las cuotas del mes',
            self::CuentaPropia => 'Ver y pagar la cuenta de su unidad',
            self::PagosAprobar => 'Aprobar pagos de residentes',
            self::GastosRegistrar => 'Registrar facturas de proveedores',
            self::ProveedoresEditar => 'Gestionar proveedores y sus cuentas',
            self::CajaChicaRegistrar => 'Registrar gastos de la caja chica',
            self::GastosAprobarN1 => 'Aprobar facturas (1.er nivel)',
            self::GastosAprobarN2 => 'Aprobar facturas grandes (2.º nivel)',
            self::GastosPagar => 'Pagar facturas a proveedores',
            self::PeriodosCerrar => 'Cerrar el mes',
            self::PeriodosReabrir => 'Reabrir un mes cerrado',
            self::ConciliacionGestionar => 'Conciliar los estados de cuenta del banco',
            self::MorosidadGestionar => 'Gestionar la morosidad: recordatorios, convenios y restricciones',
            self::ReservasGestionar => 'Gestionar áreas comunes: reglas, agenda y reservas',
            self::ReservasPropias => 'Reservar áreas comunes para su unidad',
            self::ReservasGarita => 'Dar ingreso y recibir las áreas reservadas del día',
            self::VisitasPropias => 'Autorizar visitas a su unidad',
            self::GaritaRegistrar => 'Validar el QR y registrar ingresos y salidas en la garita',
            self::GaritaBitacora => 'Consultar la bitácora de garita',
            self::PaquetesGestionar => 'Recibir y entregar los paquetes de la garita',
            self::PaquetesPropios => 'Ver los paquetes de su unidad',
            self::AnunciosPublicar => 'Publicar anuncios y ver quién los leyó',
            self::AvisosPropios => 'Leer y confirmar los avisos de la administración',
            self::IncidenciasGestionar => 'Gestionar las incidencias reportadas',
            self::IncidenciasReportar => 'Reportar incidencias y seguirlas',
            self::PlataformaCondominios => 'Gestionar condominios',
            self::PlataformaCondominiosEditar => 'Editar e inactivar condominios',
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
            $this->modulo() === 'reservas' => 'Áreas comunes',
            $this->modulo() === 'garita' => 'Garita',
            $this->modulo() === 'comunicacion' => 'Comunicación',
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
            self::GastosRegistrar, self::ProveedoresEditar, self::GastosAprobarN1, self::GastosPagar, self::CajaChicaRegistrar,
            self::PeriodosCerrar, self::PeriodosReabrir, self::ConciliacionGestionar, self::MorosidadGestionar, self::ReservasGestionar,
        ], true);
    }

    /** Permiso que modifica datos (un rol de solo lectura no lo recibe). */
    public function esEscritura(): bool
    {
        return in_array($this, [self::UnidadesEditar, self::UsuariosGestionar, self::AmenidadesGestionar, self::CondominioEditar, self::CuotasEmitir, self::PagosAprobar, self::GastosRegistrar, self::ProveedoresEditar, self::GastosAprobarN1, self::GastosAprobarN2, self::GastosPagar, self::CajaChicaRegistrar, self::PeriodosCerrar, self::PeriodosReabrir, self::ConciliacionGestionar, self::MorosidadGestionar, self::ReservasGestionar, self::ReservasGarita, self::GaritaRegistrar, self::PaquetesGestionar, self::AnunciosPublicar, self::IncidenciasGestionar], true);
    }

    public function esDePlataforma(): bool
    {
        return $this->modulo() === 'plataforma';
    }
}
