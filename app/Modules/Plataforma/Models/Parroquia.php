<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Parroquia urbana o rural del Ecuador (código INEC). Catálogo de plataforma.
 *
 * @property string $codigo
 * @property string $canton_codigo
 * @property string $nombre
 */
class Parroquia extends Model
{
    protected $table = 'ubicacion_parroquias';

    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['codigo', 'canton_codigo', 'nombre'];
}
