<?php

namespace App\Modules\Comunicacion\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Una foto de una incidencia.
 *
 * @property int $incidencia_id
 * @property string $ruta
 * @property int $orden
 */
class IncidenciaFoto extends Model
{
    use BelongsToCondominio;

    protected $table = 'incidencia_fotos';

    protected $fillable = ['incidencia_id', 'ruta', 'orden'];
}
