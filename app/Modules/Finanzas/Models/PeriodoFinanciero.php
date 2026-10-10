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
 * @property int|null $cerrado_por
 * @property Carbon|null $cerrado_en
 * @property array<string, string>|null $resumen_cierre
 * @property int|null $reabierto_por
 * @property Carbon|null $reabierto_en
 * @property string|null $motivo_reapertura
 */
class PeriodoFinanciero extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ABIERTO = 'abierto';

    public const CERRADO = 'cerrado';

    protected $table = 'periodos_financieros';

    protected $fillable = [
        'periodo', 'estado', 'emitido_en', 'cerrado_por', 'cerrado_en', 'resumen_cierre', 'reabierto_por', 'reabierto_en', 'motivo_reapertura',
    ];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'emitido_en' => 'datetime', 'cerrado_en' => 'datetime', 'reabierto_en' => 'datetime', 'resumen_cierre' => 'array'];
    }
}
