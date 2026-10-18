<?php

namespace App\Modules\Comunicacion\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un comentario en una incidencia. Las notas internas solo las ve la administración.
 *
 * @property int $incidencia_id
 * @property int|null $user_id
 * @property bool $de_administracion
 * @property bool $interna
 * @property string $texto
 * @property Carbon $created_at
 */
class IncidenciaComentario extends Model
{
    use BelongsToCondominio;

    protected $table = 'incidencia_comentarios';

    protected $fillable = ['incidencia_id', 'user_id', 'de_administracion', 'interna', 'texto'];

    protected function casts(): array
    {
        return ['de_administracion' => 'boolean', 'interna' => 'boolean'];
    }
}
