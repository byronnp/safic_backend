<?php

namespace App\Core\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Los cambios de este modelo de plataforma quedan en la bitácora de plataforma
 * (crear, editar, eliminar). El modelo declara su `entidad` y cómo se llama.
 *
 *     use RegistraBitacora;
 *     public function bitacoraEntidad(): string { return 'menu'; }
 *     public function bitacoraEtiqueta(): string { return $this->etiqueta; }
 *
 * Opcionales: `bitacoraCondominioId()` y la propiedad `$bitacoraExcluir` (campos que no
 * se guardan, además de los datos personales que BitacoraPlataforma ya descarta).
 *
 * @mixin Model
 */
trait RegistraBitacora
{
    abstract public function bitacoraEntidad(): string;

    abstract public function bitacoraEtiqueta(): string;

    public function bitacoraCondominioId(): ?int
    {
        return null;
    }

    public static function bootRegistraBitacora(): void
    {
        static::created(function (Model $m): void {
            /** @var Model&self $m */
            BitacoraPlataforma::registrar('creado', $m->bitacoraEntidad(), $m->getKey(), $m->bitacoraEtiqueta(), null, $m->bitacoraValores($m->getAttributes()), $m->bitacoraCondominioId());
        });

        static::updated(function (Model $m): void {
            /** @var Model&self $m */
            $cambios = $m->getChanges();
            unset($cambios['updated_at']);
            $antes = array_intersect_key($m->getRawOriginal(), $cambios);
            BitacoraPlataforma::registrar('actualizado', $m->bitacoraEntidad(), $m->getKey(), $m->bitacoraEtiqueta(), $m->bitacoraValores($antes), $m->bitacoraValores($cambios), $m->bitacoraCondominioId());
        });

        static::deleted(function (Model $m): void {
            /** @var Model&self $m */
            BitacoraPlataforma::registrar('eliminado', $m->bitacoraEntidad(), $m->getKey(), $m->bitacoraEtiqueta(), $m->bitacoraValores($m->getAttributes()), null, $m->bitacoraCondominioId());
        });
    }

    /**
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    protected function bitacoraValores(array $valores): array
    {
        /** @var list<string> $excluir */
        $excluir = property_exists($this, 'bitacoraExcluir') ? $this->bitacoraExcluir : [];

        return array_diff_key($valores, array_flip([...$excluir, 'updated_at', 'created_at']));
    }
}
