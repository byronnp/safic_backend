<?php

namespace App\Core\Permissions;

/**
 * Nombres de roles que el código necesita escribir (p. ej. «el presidente firma»). El catálogo vive en la base: etiqueta, ámbito,
 * reglas y permisos por defecto salen de `roles` (ver App\Core\Access\Catalogo y database/data/acceso.php). Un rol nuevo es un dato
 * y no necesita un caso aquí.
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
}
