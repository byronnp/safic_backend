<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un punto del orden del día.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $orden
 * @property string $titulo
 * @property string|null $descripcion
 * @property string $tipo informativo|votacion|eleccion|reforma_reglamento
 * @property string $mayoria ninguna|simple|calificada
 * @property string|null $umbral_pct
 * @property string|null $base presentes|total
 * @property string|null $adjunto_ruta
 * @property string|null $adjunto_nombre
 */
class AsambleaPunto extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const INFORMATIVO = 'informativo';

    public const VOTACION = 'votacion';

    public const ELECCION = 'eleccion';

    public const REFORMA = 'reforma_reglamento';

    public const TIPOS = [self::INFORMATIVO, self::VOTACION, self::ELECCION, self::REFORMA];

    protected $table = 'asamblea_puntos';

    protected $fillable = ['asamblea_id', 'orden', 'titulo', 'descripcion', 'tipo', 'mayoria', 'umbral_pct', 'base', 'adjunto_ruta', 'adjunto_nombre'];

    protected function casts(): array
    {
        return ['umbral_pct' => 'decimal:2'];
    }

    /** ¿Se vota? (los informativos solo se tratan) */
    public function seVota(): bool
    {
        return $this->tipo !== self::INFORMATIVO;
    }
}
