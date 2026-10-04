<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Provincia del Ecuador (código INEC). Catálogo de plataforma.
 *
 * @property string $codigo
 * @property string $nombre
 * @property string|null $latitud
 * @property string|null $longitud
 */
class Provincia extends Model
{
    protected $table = 'ubicacion_provincias';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'latitud', 'longitud'];

    /**
     * @return HasMany<Canton, $this>
     */
    public function cantones(): HasMany
    {
        return $this->hasMany(Canton::class, 'provincia_codigo', 'codigo');
    }
}
