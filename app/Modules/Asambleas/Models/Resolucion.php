<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una resolución de la asamblea: lo que se aprobó, vigente desde el día de la asamblea.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $acta_id
 * @property int|null $punto_id
 * @property string $texto
 * @property Carbon $vigente_desde
 */
class Resolucion extends Model
{
    use BelongsToCondominio;

    protected $table = 'resoluciones';

    protected $fillable = ['asamblea_id', 'acta_id', 'punto_id', 'texto', 'vigente_desde'];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date'];
    }
}
