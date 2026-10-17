<?php

namespace App\Modules\Comunicacion\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una unidad que recibe un anuncio, y si ya lo leyó o confirmó su lectura.
 *
 * @property int $anuncio_id
 * @property int $unidad_id
 * @property Carbon|null $leido_en
 * @property int|null $leido_por
 * @property Carbon|null $confirmado_en
 * @property int|null $confirmado_por
 */
class AnuncioDestinatario extends Model
{
    use BelongsToCondominio;

    protected $table = 'anuncio_destinatarios';

    protected $fillable = ['anuncio_id', 'unidad_id', 'leido_en', 'leido_por', 'confirmado_en', 'confirmado_por'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime', 'confirmado_en' => 'datetime'];
    }
}
