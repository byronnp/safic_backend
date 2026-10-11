<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Diferencia entre el banco y la app que alguien justificó con un motivo.
 *
 * @property int $estado_cuenta_id
 * @property string $monto
 * @property string $motivo
 * @property int|null $user_id
 */
class AjusteConciliacion extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'ajustes_conciliacion';

    protected $fillable = ['estado_cuenta_id', 'monto', 'motivo', 'user_id'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }
}
