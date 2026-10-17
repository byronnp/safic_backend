<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Acuerdo de pagar la deuda vencida de una unidad en cuotas mensuales iguales (los centavos que
 * sobran van a las primeras). Los pagos aprobados desde `desde` cubren las cuotas en orden.
 *
 * @property int $id
 * @property int $unidad_id
 * @property string $monto_total
 * @property int $cuotas
 * @property Carbon $desde
 * @property Carbon $primera_cuota_el
 * @property string $estado activo|cumplido|cancelado
 * @property string|null $motivo_cancelacion
 * @property int|null $creado_por
 */
class ConvenioPago extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ACTIVO = 'activo';

    public const CUMPLIDO = 'cumplido';

    public const CANCELADO = 'cancelado';

    protected $table = 'convenios_pago';

    protected $fillable = ['unidad_id', 'monto_total', 'cuotas', 'desde', 'primera_cuota_el', 'estado', 'motivo_cancelacion', 'creado_por'];

    protected function casts(): array
    {
        return ['monto_total' => 'decimal:2', 'cuotas' => 'integer', 'desde' => 'date', 'primera_cuota_el' => 'date'];
    }
}
