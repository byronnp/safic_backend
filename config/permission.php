<?php

/*
 * SAFIC: solo sobrescribimos lo necesario de spatie/laravel-permission.
 * El resto de claves viene de la configuración del paquete (mergeConfigFrom).
 *
 * - teams = true: el "team" es el condominio. Los roles son globales
 *   (roles.condominio_id nulo, un solo catálogo para todos los condominios)
 *   y las asignaciones se hacen por condominio (model_has_roles.condominio_id).
 * - Las asignaciones de plataforma (super_admin, soporte…) usan condominio_id = 0.
 */

return [
    'teams' => true,

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'condominio_id',
    ],
];
