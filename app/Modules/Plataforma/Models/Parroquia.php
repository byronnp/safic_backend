<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parroquia urbana o rural (código INEC de seis dígitos). Catálogo de plataforma.
 *
 * @property int $id
 * @property int $canton_id
 * @property string $codigo
 * @property string $nombre
 */
class Parroquia extends Model
{
    protected $table = 'parroquias';

    protected $fillable = ['canton_id', 'codigo', 'nombre'];

    /**
     * @return BelongsTo<Canton, $this>
     */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class);
    }
}
