<?php

namespace App\Modules\Garita\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * La pregunta del guardia a una unidad: «llegó X, ¿lo dejo pasar?».
 *
 * @property int $id
 * @property int $unidad_id
 * @property string $visitante_nombre
 * @property string $tipo visita|delivery|proveedor
 * @property string|null $placa
 * @property int|null $creada_por
 * @property string $estado pendiente|permitida|rechazada|cancelada|usada
 * @property Carbon $expira_en
 * @property int|null $respondida_por
 * @property Carbon|null $respondida_en
 * @property int|null $acceso_id
 * @property Carbon $created_at
 */
class SolicitudIngreso extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PENDIENTE = 'pendiente';

    public const PERMITIDA = 'permitida';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    public const USADA = 'usada';

    /** Minutos que el residente tiene para responder. */
    public const MINUTOS_PARA_RESPONDER = 3;

    protected $table = 'solicitudes_ingreso';

    protected $fillable = [
        'unidad_id', 'visitante_nombre', 'tipo', 'placa', 'creada_por', 'estado', 'expira_en', 'respondida_por', 'respondida_en', 'acceso_id',
    ];

    protected function casts(): array
    {
        return ['expira_en' => 'datetime', 'respondida_en' => 'datetime'];
    }

    /** Una pendiente que ya pasó su hora se trata como vencida, sin esperar a un proceso que la cierre. */
    public function estadoEfectivo(): string
    {
        return $this->estado === self::PENDIENTE && $this->expira_en->isPast() ? 'vencida' : $this->estado;
    }
}
