<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un voto: el de una unidad en una votación. No se edita ni se borra; el hash encadena cada voto con el anterior.
 *
 * @property int $id
 * @property int $votacion_id
 * @property int $unidad_id
 * @property string $opcion si|no|abstencion
 * @property string $alicuota
 * @property int|null $emitido_por
 * @property string $canal app|mesa
 * @property Carbon $emitido_en
 * @property string $hash
 */
class Voto extends Model
{
    use BelongsToCondominio;

    protected $table = 'votos';

    protected $fillable = ['votacion_id', 'unidad_id', 'opcion', 'alicuota', 'emitido_por', 'canal', 'emitido_en', 'hash'];

    protected function casts(): array
    {
        return ['emitido_en' => 'datetime'];
    }
}
