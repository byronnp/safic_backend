<?php

namespace App\Modules\Amenidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Amenidad de un condominio (copiada del catálogo global al elegirla).
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $amenidad_catalogo_id
 * @property string $nombre
 * @property int $cantidad
 * @property bool $reservable
 * @property bool $esencial
 * @property bool $requiere_aprobacion
 * @property bool $activa
 */
class CondominioAmenidad extends Model
{
    use BelongsToCondominio;

    protected $table = 'condominio_amenidades';

    protected $fillable = ['amenidad_catalogo_id', 'nombre', 'cantidad', 'reservable', 'esencial', 'requiere_aprobacion', 'activa'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'reservable' => 'boolean',
            'esencial' => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'activa' => 'boolean',
        ];
    }
}
