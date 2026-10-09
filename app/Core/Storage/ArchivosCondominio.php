<?php

namespace App\Core\Storage;

use App\Core\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivos de un condominio (comprobantes, actas, fotos) en el disco `archivos`
 * (S3 en AWS, MinIO en local).
 *
 * Todo vive bajo `condominios/{id}/{carpeta}/`: el prefijo sale del condominio
 * activo y no se recibe de afuera, así un condominio no puede escribir ni
 * pedir el enlace de un archivo de otro. El bucket es privado: se entrega solo
 * un enlace temporal.
 */
final class ArchivosCondominio
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Guarda el archivo con un nombre aleatorio y devuelve su ruta (la que se
     * guarda en la base). El nombre original no se usa para no filtrar datos
     * ni permitir rutas manipuladas.
     */
    public function guardar(UploadedFile $archivo, string $carpeta): string
    {
        $extension = strtolower($archivo->guessExtension() ?? 'bin');
        $ruta = $this->prefijo($carpeta).'/'.Str::uuid().'.'.$extension;

        $this->disco()->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private']);

        return $ruta;
    }

    /** Enlace temporal de lectura. Falla si la ruta no es del condominio activo. */
    public function urlTemporal(string $ruta, ?DateTimeInterface $expira = null): string
    {
        $this->exigirPropio($ruta);

        return $this->disco()->temporaryUrl($ruta, $expira ?? now()->addMinutes(10));
    }

    /** Contenido del archivo (para servirlo por la API). Falla si la ruta no es del condominio activo. */
    public function contenido(string $ruta): ?string
    {
        $this->exigirPropio($ruta);

        return $this->disco()->get($ruta);
    }

    public function eliminar(string $ruta): void
    {
        $this->exigirPropio($ruta);

        $this->disco()->delete($ruta);
    }

    public function existe(string $ruta): bool
    {
        return $this->perteneceAlCondominio($ruta) && $this->disco()->exists($ruta);
    }

    public function perteneceAlCondominio(string $ruta): bool
    {
        return str_starts_with($ruta, 'condominios/'.$this->tenant->require().'/')
            && ! str_contains($ruta, '..');
    }

    private function exigirPropio(string $ruta): void
    {
        if (! $this->perteneceAlCondominio($ruta)) {
            throw new ArchivoAjenoException;
        }
    }

    private function prefijo(string $carpeta): string
    {
        $carpeta = trim($carpeta, '/');

        if ($carpeta === '' || ! preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $carpeta)) {
            throw new \InvalidArgumentException('Carpeta de archivos inválida.');
        }

        return 'condominios/'.$this->tenant->require().'/'.$carpeta;
    }

    private function disco(): Filesystem
    {
        return Storage::disk(config('filesystems.disco_archivos'));
    }
}
