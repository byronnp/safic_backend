<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Estado de cuenta del banco importado: una cuenta y un mes.
 *
 * @property int $id
 * @property int $cuenta_bancaria_id
 * @property Carbon $periodo
 * @property string $archivo_nombre
 * @property string|null $saldo_inicial
 * @property string|null $saldo_final
 * @property int $duplicados
 * @property int|null $importado_por
 */
class EstadoCuentaBancario extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'estados_cuenta_bancarios';

    protected $fillable = ['cuenta_bancaria_id', 'periodo', 'archivo_nombre', 'saldo_inicial', 'saldo_final', 'duplicados', 'importado_por'];

    protected function casts(): array
    {
        return ['periodo' => 'date', 'saldo_inicial' => 'decimal:2', 'saldo_final' => 'decimal:2'];
    }

    /** @return HasMany<MovimientoBancario, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class, 'estado_cuenta_id');
    }
}
