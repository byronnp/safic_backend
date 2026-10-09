<?php

namespace App\Core\Marca\Http\Controllers;

use App\Core\Marca\MarcaPublica;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo del condominio, sin sesión: aparece en el login, en recibos y en correos.
 * No es un dato personal. Solo sirve PNG ya validados al subirlos.
 */
class LogoPublicoController
{
    public function show(TenantContext $tenant, ArchivosCondominio $archivos, string $codigo, string $variante): Response
    {
        $condominio = in_array($variante, MarcaPublica::VARIANTES, true)
            ? Condominio::query()->where('codigo', $codigo)->first()
            : null;
        $ruta = $condominio === null ? null : MarcaPublica::rutaLogo($condominio, $variante);

        $contenido = $ruta === null ? null : $tenant->run($condominio->id, fn () => $archivos->contenido($ruta));

        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
