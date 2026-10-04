<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Provincia (código INEC de dos dígitos). Catálogo de plataforma.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 */
class Provincia extends Model
{
    protected $table = 'provincias';

    protected $fillable = ['codigo', 'nombre'];

    /**
     * @return HasMany<Canton, $this>
     */
    public function cantones(): HasMany
    {
        return $this->hasMany(Canton::class);
    }
}
