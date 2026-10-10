<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Mes financiero del condominio: se abre al emitir las cuotas y se cierra con la conciliación.
 *
 * @property int $id
 * @property int $condominio_id
 * @property Carbon $periodo Siempre el día 1
 * @property string $estado abierto|cerrado
 * @property Carbon|null $emitido_en
 */
class PeriodoFinanciero extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ABIERTO = 'abierto';

    public const CERRADO = 'cerrado';

    protected $table = 'periodos_financieros';

    protected $fillable = ['periodo', 'estado', 'emitido_en'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'emitido_en' => 'datetime'];
    }
}
