<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Database\Factories\BloqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Bloque, torre o etapa del condominio. Primera tabla de condominio: sirve de
 * patrón para las demás (trait BelongsToCondominio + RLS en la migración).
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $nombre
 * @property int $orden
 */
class Bloque extends Model
{
    /** @use HasFactory<BloqueFactory> */
    use BelongsToCondominio, HasFactory;

    protected $table = 'bloques';

    protected $fillable = ['nombre', 'orden'];

    protected function casts(): array
    {
        return ['orden' => 'integer'];
    }

    protected static function newFactory(): BloqueFactory
    {
        return BloqueFactory::new();
    }
}
