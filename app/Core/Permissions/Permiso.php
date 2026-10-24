<?php

namespace App\Core\Permissions;

/**
 * Nombres de permisos que el código necesita escribir (una ruta, una regla). El catálogo vive en la base: etiqueta, grupo, ámbito,
 * si es administrativo o de escritura y qué roles lo traen salen de `permissions` (ver App\Core\Access\Catalogo y
 * database/data/acceso.php). Un permiso nuevo no necesita un caso aquí salvo que el código lo nombre; una ruta puede usar la clave
 * como texto (`permission:modulo.accion`).
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

    // Asambleas · preparar y convocar (administración y directiva)
    case AsambleasPreparar = 'asambleas.preparar';

    // Asambleas · lo del propietario: ver sus asambleas y dar poder a un representante
    case AsambleasPropias = 'asambleas.propias';

    // Asambleas · registrar la asistencia en mesa (secretario y directiva)
    case AsambleasRegistrar = 'asambleas.registrar';

    // Asambleas · instalar y dirigir la mesa (presidente; el vicepresidente lo subroga)
    case AsambleasInstalar = 'asambleas.instalar';

    // Asambleas · firmar el acta (presidente o su subrogante y secretario)
    case AsambleasFirmar = 'asambleas.firmar';

    // Plataforma (solo roles de plataforma, condominio_id = 0)
    case PlataformaCondominios = 'plataforma.condominios';
    case PlataformaCondominiosEditar = 'plataforma.condominios-editar';
    case PlataformaRoles = 'plataforma.roles';
    case PlataformaCobranza = 'plataforma.cobranza';
    case PlataformaAuditoria = 'plataforma.auditoria';
}
