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
 * @property string $estado pendiente|confirmada|en_uso|finalizada|rechazada|cancelada|vencida
 * @property string $costo
 * @property string $garantia
 * @property int|null $cuota_uso_id
 * @property int|null $cuota_garantia_id
 * @property Carbon|null $limite_pago_en
 * @property Carbon|null $entrega_en
 * @property int|null $entrega_por
 * @property string|null $danos
 * @property string|null $danos_decision sin_cargo|descontar_garantia|cobrar_cuenta
 * @property string|null $danos_monto
 * @property int|null $danos_cuota_id
 * @property string|null $danos_nota
 * @property Carbon|null $danos_resuelta_en
 * @property string|null $garantia_devuelta_monto
 * @property Carbon|null $garantia_devuelta_en
 * @property string|null $garantia_devuelta_forma efectivo|transferencia
 * @property string|null $garantia_devuelta_referencia
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

    public const EN_USO = 'en_uso';

    public const FINALIZADA = 'finalizada';

    /** Las que ocupan el horario. */
    public const VIVAS = [self::PENDIENTE, self::CONFIRMADA, self::EN_USO];

    protected $table = 'reservas';

    public const DANOS_SIN_CARGO = 'sin_cargo';

    public const DANOS_DESCONTAR_GARANTIA = 'descontar_garantia';

    public const DANOS_COBRAR_CUENTA = 'cobrar_cuenta';

    public const FORMAS_DEVOLUCION = ['efectivo', 'transferencia'];

    /** Fotos de daños que el guardia puede adjuntar al recibir el área. */
    public const MAXIMO_FOTOS_DANOS = 5;

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
        'resuelta_por', 'resuelta_en', 'motivo_rechazo', 'ingreso_en', 'ingreso_por', 'entrega_en', 'entrega_por', 'danos',
        'danos_decision', 'danos_monto', 'danos_cuota_id', 'danos_nota', 'danos_resuelta_por', 'danos_resuelta_en',
        'garantia_devuelta_monto', 'garantia_devuelta_en', 'garantia_devuelta_forma', 'garantia_devuelta_referencia', 'garantia_devuelta_por', 'garantia_devuelta_registrada_en',
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
            'ingreso_en' => 'datetime',
            'entrega_en' => 'datetime',
            'danos_monto' => 'decimal:2',
            'danos_resuelta_en' => 'datetime',
            'garantia_devuelta_monto' => 'decimal:2',
            'garantia_devuelta_en' => 'date',
            'garantia_devuelta_registrada_en' => 'datetime',
        ];
    }
}
