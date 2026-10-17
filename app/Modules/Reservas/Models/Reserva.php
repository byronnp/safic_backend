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

    protected $fillable = [
        'amenidad_id', 'unidad_id', 'solicitante_id', 'fecha', 'inicio', 'fin', 'estado', 'costo', 'garantia',
        'reglamento_aceptado_en', 'cancelada_en', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'costo' => 'decimal:2',
            'garantia' => 'decimal:2',
            'reglamento_aceptado_en' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
    }
}
