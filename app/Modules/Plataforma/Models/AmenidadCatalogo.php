<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Amenidad del catálogo global (la administra el super admin). Sin RLS.
 *
 * @property int $id
 * @property string $nombre
 * @property string $categoria
 * @property string|null $descripcion
 * @property bool $reservable
 * @property bool $esencial
 * @property bool $requiere_aprobacion
 * @property int|null $capacidad
 * @property int|null $duracion_maxima_min
 * @property int $orden
 * @property bool $activa
 */
class AmenidadCatalogo extends Model
{
    protected $table = 'amenidades_catalogo';

    protected $fillable = [
        'nombre', 'categoria', 'descripcion', 'reservable', 'esencial', 'requiere_aprobacion',
        'capacidad', 'duracion_maxima_min', 'orden', 'activa',
    ];

    protected function casts(): array
    {
        return [
            'reservable' => 'boolean',
            'esencial' => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'capacidad' => 'integer',
            'duracion_maxima_min' => 'integer',
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }
}
