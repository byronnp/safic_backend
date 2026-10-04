<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cantón del Ecuador (código INEC). Catálogo de plataforma.
 *
 * @property string $codigo
 * @property string $provincia_codigo
 * @property string $nombre
 * @property string|null $latitud
 * @property string|null $longitud
 */
class Canton extends Model
{
    protected $table = 'ubicacion_cantones';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'provincia_codigo', 'nombre', 'latitud', 'longitud'];

    /**
     * @return HasMany<Parroquia, $this>
     */
    public function parroquias(): HasMany
    {
        return $this->hasMany(Parroquia::class, 'canton_codigo', 'codigo');
    }
}
