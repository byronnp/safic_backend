<?php

namespace App\Modules\Reservas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Reserva de un área común por una unidad. Fecha y horas son locales del condominio.
 *
 * @property int $id
 * @property int $amenidad_id
 * @property int $unidad_id
 * @property int|null $solicitante_id
 * @property Carbon $fecha
 * @property string $inicio HH:MM:SS
 * @property string $fin HH:MM:SS
 * @property string $estado pendiente|confirmada|rechazada|cancelada|vencida
 * @property string $costo
 * @property string $garantia
 * @property int|null $cuota_uso_id
 * @property int|null $cuota_garantia_id
 * @property Carbon|null $limite_pago_en
 * @property Carbon $created_at
 */
class Reserva extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PENDIENTE = 'pendiente';

    public const CONFIRMADA = 'confirmada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    public const VENCIDA = 'vencida';

    /** Las que ocupan el horario. */
    public const VIVAS = [self::PENDIENTE, self::CONFIRMADA];

    protected $table = 'reservas';

    /** Horas que la administración tiene para responder una solicitud. */
    public const HORAS_PARA_RESPONDER = 48;

    /** Horas antes de empezar en que el cobro debe estar pagado o la reserva se cancela sola. */
    public const HORAS_PARA_PAGAR = 24;

    /** @return list<int> */
    public function cuotaIds(): array
    {
        return array_values(array_filter([$this->cuota_uso_id, $this->cuota_garantia_id]));
    }

    protected $fillable = [
        'amenidad_id', 'unidad_id', 'solicitante_id', 'fecha', 'inicio', 'fin', 'estado', 'costo', 'garantia',
        'reglamento_aceptado_en', 'cancelada_en', 'motivo_cancelacion', 'cuota_uso_id', 'cuota_garantia_id', 'limite_pago_en',
        'resuelta_por', 'resuelta_en', 'motivo_rechazo',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'costo' => 'decimal:2',
            'garantia' => 'decimal:2',
            'reglamento_aceptado_en' => 'datetime',
            'cancelada_en' => 'datetime',
            'limite_pago_en' => 'datetime',
            'resuelta_en' => 'datetime',
        ];
    }
}
