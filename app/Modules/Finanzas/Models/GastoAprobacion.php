<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Decisión de un nivel de aprobación sobre una factura. `en_subrogacion` marca que la tomó el
 * vicepresidente en lugar del presidente.
 *
 * @property int $gasto_id
 * @property int $nivel 1|2
 * @property string $decision aprobada|rechazada
 * @property int|null $user_id
 * @property string|null $comentario
 * @property bool $en_subrogacion
 */
class GastoAprobacion extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'gasto_aprobaciones';

    protected $fillable = ['gasto_id', 'nivel', 'decision', 'user_id', 'comentario', 'en_subrogacion'];

    protected function casts(): array
    {
        return ['en_subrogacion' => 'boolean'];
    }
}
