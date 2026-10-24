<?php

/**
 * Menús del sistema: la web del condominio y el panel de plataforma. MenuSeeder los carga a la base (menu_items) y desde ahí la
 * aplicación lee la base. Idempotente: solo crea las pantallas que faltan y solo asigna roles a las nuevas, así que no pisa lo que el
 * super admin cambió desde «Menú del sistema». Para una pantalla nueva: agrégala aquí con su `permiso` y corre
 * `php artisan db:seed --class=MenuSeeder`. Una hoja se asigna por defecto a los roles cuyos permisos por defecto (database/data/acceso.php)
 * incluyen el de la hoja, o a todos los roles del ámbito si no pide permiso. `clave` = `id` del frontend.
 */
return [
    'condominio' => [
        ['clave' => 'inicio', 'etiqueta' => 'Inicio', 'icono' => 'sym_r_space_dashboard', 'ruta' => 'inicio'],
        ['clave' => 'unidades', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'hijos' => [
            ['clave' => 'unidades.lista', 'etiqueta' => 'Unidades', 'icono' => 'sym_r_apartment', 'ruta' => 'unidades', 'permiso' => 'unidades.ver'],
            ['clave' => 'unidades.bloques', 'etiqueta' => 'Bloques', 'icono' => 'sym_r_domain', 'ruta' => 'bloques', 'permiso' => 'unidades.ver'],
        ]],
        ['clave' => 'finanzas', 'etiqueta' => 'Finanzas', 'icono' => 'sym_r_account_balance_wallet', 'hijos' => [
            ['clave' => 'finanzas.resumen', 'etiqueta' => 'Resumen', 'icono' => 'sym_r_dashboard', 'ruta' => 'finanzas-resumen', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.cuotas', 'etiqueta' => 'Cuotas del mes', 'icono' => 'sym_r_receipt_long', 'ruta' => 'finanzas-cuotas', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.cierre', 'etiqueta' => 'Cierre de mes', 'icono' => 'sym_r_lock', 'ruta' => 'finanzas-cierre-mes', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.conciliacion', 'etiqueta' => 'Conciliación bancaria', 'icono' => 'sym_r_account_balance', 'ruta' => 'finanzas-conciliacion', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.morosidad', 'etiqueta' => 'Morosidad', 'icono' => 'sym_r_notifications_active', 'ruta' => 'finanzas-morosidad', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.reportes', 'etiqueta' => 'Reportes', 'icono' => 'sym_r_bar_chart', 'ruta' => 'finanzas-reportes', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.pagos', 'etiqueta' => 'Pagos por aprobar', 'icono' => 'sym_r_fact_check', 'ruta' => 'finanzas-pagos-por-aprobar', 'permiso' => 'pagos.aprobar'],
            ['clave' => 'finanzas.cuentas', 'etiqueta' => 'Cuentas por pagar', 'icono' => 'sym_r_payments', 'ruta' => 'finanzas-cuentas-por-pagar', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.recurrentes', 'etiqueta' => 'Gastos recurrentes', 'icono' => 'sym_r_autorenew', 'ruta' => 'finanzas-recurrentes', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.caja-chica', 'etiqueta' => 'Caja chica', 'icono' => 'sym_r_savings', 'ruta' => 'finanzas-caja-chica', 'permiso' => 'cajachica.registrar'],
            ['clave' => 'finanzas.proveedores', 'etiqueta' => 'Proveedores', 'icono' => 'sym_r_local_shipping', 'ruta' => 'finanzas-proveedores', 'permiso' => 'finanzas.ver'],
            ['clave' => 'finanzas.aprobaciones', 'etiqueta' => 'Aprobaciones', 'icono' => 'sym_r_approval', 'ruta' => 'finanzas-aprobaciones', 'permiso' => 'gastos.aprobar-n2'],
        ]],
        ['clave' => 'areas', 'etiqueta' => 'Áreas comunes', 'icono' => 'sym_r_pool', 'hijos' => [
            ['clave' => 'areas.agenda', 'etiqueta' => 'Agenda', 'icono' => 'sym_r_calendar_month', 'ruta' => 'areas-agenda', 'permiso' => 'reservas.gestionar'],
            ['clave' => 'areas.reglas', 'etiqueta' => 'Áreas y reglas', 'icono' => 'sym_r_rule', 'ruta' => 'areas-reglas', 'permiso' => 'reservas.gestionar'],
        ]],
        ['clave' => 'seguridad', 'etiqueta' => 'Seguridad y comunicación', 'icono' => 'sym_r_shield_person', 'hijos' => [
            ['clave' => 'seguridad.bitacora', 'etiqueta' => 'Bitácora de garita', 'icono' => 'sym_r_badge', 'ruta' => 'garita-bitacora', 'permiso' => 'garita.bitacora'],
            ['clave' => 'seguridad.anuncios', 'etiqueta' => 'Anuncios', 'icono' => 'sym_r_campaign', 'ruta' => 'comunicacion-anuncios', 'permiso' => 'anuncios.publicar'],
            ['clave' => 'seguridad.incidencias', 'etiqueta' => 'Incidencias', 'icono' => 'sym_r_support_agent', 'ruta' => 'comunicacion-incidencias', 'permiso' => 'incidencias.gestionar'],
        ]],
        ['clave' => 'asambleas', 'etiqueta' => 'Asambleas', 'icono' => 'sym_r_how_to_vote', 'hijos' => [
            ['clave' => 'asambleas.preparar', 'etiqueta' => 'Preparar asamblea', 'icono' => 'sym_r_edit_calendar', 'ruta' => 'asambleas-preparar', 'permiso' => 'asambleas.preparar'],
            ['clave' => 'asambleas.registro', 'etiqueta' => 'Registro de asistencia', 'icono' => 'sym_r_how_to_reg', 'ruta' => 'asambleas-registro', 'permiso' => 'asambleas.registrar'],
            ['clave' => 'asambleas.mesa', 'etiqueta' => 'Mesa de la asamblea', 'icono' => 'sym_r_groups', 'ruta' => 'asambleas-mesa', 'permiso' => 'asambleas.instalar'],
        ]],
        ['clave' => 'configuracion', 'etiqueta' => 'Configuración', 'icono' => 'sym_r_settings', 'seccion' => true, 'hijos' => [
            ['clave' => 'configuracion.condominio', 'etiqueta' => 'Datos del condominio', 'icono' => 'sym_r_domain', 'ruta' => 'configuracion-condominio', 'permiso' => 'condominio.editar'],
            ['clave' => 'configuracion.cobro', 'etiqueta' => 'Cobro de cuotas', 'icono' => 'sym_r_request_quote', 'ruta' => 'configuracion-cobro', 'permiso' => 'condominio.editar'],
            ['clave' => 'configuracion.amenidades', 'etiqueta' => 'Amenidades', 'icono' => 'sym_r_pool', 'ruta' => 'configuracion-amenidades', 'permiso' => 'amenidades.gestionar'],
            ['clave' => 'configuracion.usuarios', 'etiqueta' => 'Usuarios', 'icono' => 'sym_r_manage_accounts', 'ruta' => 'configuracion-usuarios', 'permiso' => 'usuarios.gestionar'],
            ['clave' => 'configuracion.roles', 'etiqueta' => 'Roles', 'icono' => 'sym_r_admin_panel_settings', 'ruta' => 'configuracion-roles', 'permiso' => 'usuarios.gestionar'],
        ]],
    ],

    'plataforma' => [
        ['clave' => 'plataforma.condominios', 'etiqueta' => 'Condominios', 'icono' => 'sym_r_location_city', 'ruta' => 'plataforma-condominios', 'permiso' => 'plataforma.condominios'],
        ['clave' => 'plataforma.amenidades', 'etiqueta' => 'Catálogo de amenidades', 'icono' => 'sym_r_category', 'ruta' => 'plataforma-amenidades', 'permiso' => 'plataforma.condominios'],
        ['clave' => 'plataforma.acceso', 'etiqueta' => 'Acceso', 'icono' => 'sym_r_lock', 'seccion' => true, 'hijos' => [
            ['clave' => 'plataforma.roles', 'etiqueta' => 'Roles y permisos', 'icono' => 'sym_r_shield_person', 'ruta' => 'plataforma-roles', 'permiso' => 'plataforma.roles'],
            ['clave' => 'plataforma.menu', 'etiqueta' => 'Menú del sistema', 'icono' => 'sym_r_menu_open', 'ruta' => 'plataforma-menu', 'permiso' => 'plataforma.roles'],
        ]],
    ],
];
