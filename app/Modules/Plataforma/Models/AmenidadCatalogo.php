<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Amenidad del catálogo global. Catálogo de plataforma (sin RLS).
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property string $icono
 * @property bool $reservable
 * @property bool $esencial
 * @property int $orden
 * @property bool $activo
 */
class AmenidadCatalogo extends Model
{
    protected $table = 'amenidades_catalogo';

    protected $fillable = ['clave', 'nombre', 'icono', 'reservable', 'esencial', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'reservable' => 'boolean',
            'esencial' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @param  Builder<AmenidadCatalogo>  $query
     */
    public function scopeActivas(Builder $query): void
    {
        $query->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
