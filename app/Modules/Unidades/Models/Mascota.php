<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property int $condominio_id
 * @property int $unidad_id
 * @property string $nombre
 * @property string $especie
 * @property string|null $raza
 */
class Mascota extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio, SoftDeletes;

    public const ESPECIES = ['perro', 'gato', 'ave', 'otro'];

    protected $table = 'mascotas';

    protected $fillable = ['unidad_id', 'nombre', 'especie', 'raza'];

    /** @return BelongsTo<Unidad, $this> */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }
}
