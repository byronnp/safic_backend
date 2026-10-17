<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un gasto menor pagado del fondo, con la foto de su respaldo. `abierto` hasta que se repone.
 *
 * @property int $id
 * @property Carbon $fecha
 * @property string $concepto
 * @property string|null $comercio
 * @property string $monto
 * @property string $respaldo_ruta
 * @property string $estado abierto|repuesto
 * @property int|null $gasto_id
 * @property int|null $registrado_por
 */
class CajaChicaMovimiento extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ABIERTO = 'abierto';

    public const REPUESTO = 'repuesto';

    protected $table = 'caja_chica_movimientos';

    protected $fillable = ['fecha', 'concepto', 'comercio', 'monto', 'respaldo_ruta', 'estado', 'gasto_id', 'registrado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }
}
