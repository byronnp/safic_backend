<?php

return [
    // Dirección de la SPA (enlaces en correos: invitaciones, recuperar contraseña)
    'frontend_url' => rtrim((string) env('SAFIC_FRONTEND_URL', 'http://localhost:9000'), '/'),

    // Correo de soporte de la plataforma: recibe, por ejemplo, las solicitudes de roles nuevos
    'soporte_email' => env('SAFIC_SOPORTE_EMAIL'),

    // Versión vigente del aviso de privacidad (LOPDP). Al publicar otro texto se cambia
    // y cada aceptación queda registrada con la versión que la persona vio.
    'aviso_privacidad_version' => (string) env('SAFIC_AVISO_PRIVACIDAD_VERSION', '2026-10-borrador'),

    'auth' => [
        // Refresh token rotativo
        'refresh_ttl_days' => (int) env('SAFIC_REFRESH_TTL_DAYS', 30),
        'refresh_cookie' => 'safic_refresh',
        'refresh_cookie_path' => '/api/v1/auth',
        'refresh_cookie_secure' => (bool) env('SAFIC_REFRESH_COOKIE_SECURE', true),

        // Las apps móviles envían este header y reciben el refresh token en el cuerpo
        'mobile_header' => 'X-Client-Type',
        'mobile_value' => 'mobile',
    ],
];
