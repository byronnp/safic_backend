<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * El gasto recurrente de un mes: se crea a partir de su día y espera la factura real (`gasto_id`).
 *
 * @property int $id
 * @property int $gasto_recurrente_id
 * @property Carbon $periodo
 * @property Carbon $fecha_prevista
 * @property int|null $gasto_id
 * @property bool $aprobada_automaticamente
 * @property Carbon|null $avisada_en
 */
class RecurrenteOcurrencia extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'recurrente_ocurrencias';

    protected $fillable = ['gasto_recurrente_id', 'periodo', 'fecha_prevista', 'gasto_id', 'aprobada_automaticamente', 'avisada_en'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'fecha_prevista' => 'date', 'aprobada_automaticamente' => 'boolean', 'avisada_en' => 'datetime'];
    }
}
