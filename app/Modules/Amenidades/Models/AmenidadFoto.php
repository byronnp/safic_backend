<?php

namespace App\Modules\Amenidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Foto de una amenidad. `orden` 1 es la portada.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int $amenidad_id
 * @property string $ruta
 * @property int $orden
 */
class AmenidadFoto extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    /** Máximo de fotos por amenidad. */
    public const MAXIMO = 5;

    protected $table = 'amenidad_fotos';

    protected $fillable = ['amenidad_id', 'ruta', 'orden'];

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }
}
