<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Pago de una unidad (transferencia con comprobante o efectivo). Nace pendiente y la
 * administración lo aprueba o lo rechaza.
 *
 * @property int $id
 * @property int $condominio_id
 * @property int $unidad_id
 * @property int|null $user_id
 * @property string $metodo transferencia|efectivo
 * @property string $monto
 * @property int|null $cuenta_bancaria_id
 * @property string|null $numero_comprobante
 * @property string|null $banco_origen
 * @property Carbon $fecha_pago
 * @property string|null $comprobante_ruta
 * @property string $estado pendiente|aprobado|rechazado|anulado
 * @property string|null $motivo_rechazo
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string $diferencia
 * @property string|null $pagador_nombre Quien pagó cuando no hay cuenta detrás (efectivo)
 * @property list<array{cuota_id: int, periodo: string, monto: string}>|null $cuotas_declaradas
 */
class Pago extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PENDIENTE = 'pendiente';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    public const ANULADO = 'anulado';

    protected $table = 'pagos';

    protected $fillable = [
        'unidad_id', 'user_id', 'metodo', 'monto', 'cuenta_bancaria_id', 'numero_comprobante', 'banco_origen', 'fecha_pago',
        'comprobante_ruta', 'estado', 'motivo_rechazo', 'revisado_por', 'revisado_en', 'diferencia', 'cuotas_declaradas', 'pagador_nombre',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'fecha_pago' => 'date',
            'revisado_en' => 'datetime',
            'cuotas_declaradas' => 'array',
        ];
    }
}
