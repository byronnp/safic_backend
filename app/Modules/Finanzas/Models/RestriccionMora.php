<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Restricción de áreas comunes por mora (Decreto 462). `notificada` espera sus días de aviso;
 * `activa` ya restringe; `levantada` terminó. Visitas, paquetes y seguridad nunca se restringen.
 *
 * @property int $id
 * @property int $unidad_id
 * @property string $estado notificada|activa|levantada
 * @property Carbon $notificada_en
 * @property Carbon $restringe_desde
 * @property string $deuda_al_notificar
 * @property int $meses_al_notificar
 * @property Carbon|null $levantada_en
 * @property string|null $motivo_levantamiento
 * @property int|null $levantada_por
 */
class RestriccionMora extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const NOTIFICADA = 'notificada';

    public const ACTIVA = 'activa';

    public const LEVANTADA = 'levantada';

    protected $table = 'restricciones_mora';

    protected $fillable = [
        'unidad_id', 'estado', 'notificada_en', 'restringe_desde', 'deuda_al_notificar', 'meses_al_notificar',
        'levantada_en', 'motivo_levantamiento', 'levantada_por',
    ];

    protected function casts(): array
    {
        return ['notificada_en' => 'datetime', 'restringe_desde' => 'date', 'deuda_al_notificar' => 'decimal:2', 'levantada_en' => 'datetime'];
    }
}
