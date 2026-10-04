<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cantón (código INEC de cuatro dígitos). Catálogo de plataforma.
 *
 * @property int $id
 * @property int $provincia_id
 * @property string $codigo
 * @property string $nombre
 */
class Canton extends Model
{
    protected $table = 'cantones';

    protected $fillable = ['provincia_id', 'codigo', 'nombre'];

    /**
     * @return BelongsTo<Provincia, $this>
     */
    public function provincia(): BelongsTo
    {
        return $this->belongsTo(Provincia::class);
    }

    /**
     * @return HasMany<Parroquia, $this>
     */
    public function parroquias(): HasMany
    {
        return $this->hasMany(Parroquia::class);
    }
}
