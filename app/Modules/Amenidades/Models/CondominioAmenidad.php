<?php

namespace App\Modules\Amenidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Amenidad de un condominio (copiada del catálogo global al elegirla).
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $amenidad_catalogo_id
 * @property string $nombre
 * @property string|null $categoria
 * @property int $cantidad
 * @property string|null $ubicacion
 * @property bool $reservable
 * @property bool $esencial
 * @property bool $requiere_aprobacion
 * @property bool $activa
 * @property Carbon|null $mantenimiento_hasta
 */
class CondominioAmenidad extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const CATEGORIAS = ['recreacion', 'deporte', 'social', 'servicios', 'seguridad'];

    protected $table = 'condominio_amenidades';

    protected $fillable = [
        'amenidad_catalogo_id', 'nombre', 'categoria', 'cantidad', 'ubicacion', 'reservable', 'esencial',
        'requiere_aprobacion', 'activa', 'mantenimiento_hasta',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'reservable' => 'boolean',
            'esencial' => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'activa' => 'boolean',
            'mantenimiento_hasta' => 'date',
        ];
    }

    /**
     * @return HasMany<AmenidadFoto, $this>
     */
    public function fotos(): HasMany
    {
        return $this->hasMany(AmenidadFoto::class, 'amenidad_id')->orderBy('orden')->orderBy('id');
    }
}
