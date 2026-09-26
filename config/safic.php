<?php

return [
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
