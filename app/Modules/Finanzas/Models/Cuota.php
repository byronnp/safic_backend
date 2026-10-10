<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Lo que una unidad debe en un mes. El dinero va como texto decimal ("80.00"): sin float.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int $unidad_id
 * @property Carbon $periodo
 * @property string $concepto ordinaria|extraordinaria
 * @property string $monto
 * @property string $pagado
 * @property Carbon $vence_el
 */
class Cuota extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ORDINARIA = 'ordinaria';

    protected $table = 'cuotas';

    protected $fillable = ['unidad_id', 'periodo', 'concepto', 'monto', 'pagado', 'vence_el'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'vence_el' => 'date', 'monto' => 'decimal:2', 'pagado' => 'decimal:2'];
    }
}
