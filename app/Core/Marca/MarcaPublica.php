<?php

namespace App\Core\Marca;

use App\Modules\Plataforma\Models\Condominio;

/**
 * Marca del condominio tal como la ve el cliente: colores y enlaces de los logos.
 * La ruta interna del archivo en S3 nunca sale de la API; el logo se sirve por
 * /api/v1/marca/{codigo}/logo/{variante} (público: sale en el login, recibos y correos).
 */
final class MarcaPublica
{
    public const VARIANTES = ['claro', 'oscuro'];

    /**
     * @return array{color_primario: string|null, color_acento: string|null, logo_url: string|null, logo_claro_url: string|null, logo_oscuro_url: string|null}
     */
    public static function de(Condominio $condominio): array
    {
        $marca = $condominio->marca ?? [];
        $claro = self::urlLogo($condominio, 'claro');
        $oscuro = self::urlLogo($condominio, 'oscuro');

        return [
            'color_primario' => $marca['color_primario'] ?? null,
            'color_acento' => $marca['color_acento'] ?? null,
            // logo_url: el de fondo claro (compatibilidad con el frontend)
            'logo_url' => $claro ?? $oscuro,
            'logo_claro_url' => $claro,
            'logo_oscuro_url' => $oscuro,
        ];
    }

    public static function rutaLogo(Condominio $condominio, string $variante): ?string
    {
        $ruta = ($condominio->marca ?? [])['logo_'.$variante] ?? null;

        return is_string($ruta) && $ruta !== '' ? $ruta : null;
    }

    private static function urlLogo(Condominio $condominio, string $variante): ?string
    {
        $ruta = self::rutaLogo($condominio, $variante);

        // ?v= cambia al subir otro logo, así el navegador no muestra el anterior desde su caché
        return $ruta === null ? null : "/api/v1/marca/{$condominio->codigo}/logo/{$variante}?v=".substr(md5($ruta), 0, 10);
    }
}
