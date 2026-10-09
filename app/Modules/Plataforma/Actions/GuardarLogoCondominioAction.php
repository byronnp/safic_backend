<?php

namespace App\Modules\Plataforma\Actions;

use App\Core\Marca\MarcaPublica;
use App\Core\Storage\ArchivosCondominio;
use App\Core\Tenancy\TenantContext;
use App\Modules\Plataforma\Models\Condominio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Caso de uso: subir o quitar el logo del condominio (variante clara u oscura).
 * El archivo va a S3 bajo condominios/{id}/marca/; el anterior se borra al reemplazarlo.
 */
final class GuardarLogoCondominioAction
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ArchivosCondominio $archivos,
    ) {}

    public function subir(string $variante, UploadedFile $archivo): Condominio
    {
        $nueva = $this->archivos->guardar($archivo, 'marca');

        try {
            return $this->cambiar($variante, $nueva);
        } catch (\Throwable $e) {
            // La base no quedó con la ruta: no se deja el archivo huérfano
            $this->archivos->eliminar($nueva);

            throw $e;
        }
    }

    public function quitar(string $variante): Condominio
    {
        return $this->cambiar($variante, null);
    }

    private function cambiar(string $variante, ?string $ruta): Condominio
    {
        abort_unless(in_array($variante, MarcaPublica::VARIANTES, true), 404);

        $anterior = null;

        $condominio = DB::transaction(function () use ($variante, $ruta, &$anterior): Condominio {
            $condominio = Condominio::query()->lockForUpdate()->findOrFail($this->tenant->require());
            $marca = $condominio->marca ?? [];
            $anterior = MarcaPublica::rutaLogo($condominio, $variante);

            if ($ruta === null) {
                unset($marca['logo_'.$variante]);
            } else {
                $marca['logo_'.$variante] = $ruta;
            }

            $condominio->marca = $marca;
            $condominio->save();

            return $condominio->load(['provincia', 'canton', 'parroquia']);
        });

        if ($anterior !== null && $anterior !== $ruta) {
            $this->archivos->eliminar($anterior);
        }

        return $condominio;
    }
}
