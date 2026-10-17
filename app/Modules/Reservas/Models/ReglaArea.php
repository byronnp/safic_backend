<?php

namespace App\Modules\Reservas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Reglas de uso de un área común.
 *
 * @property int $id
 * @property int $amenidad_id
 * @property string $abre HH:MM:SS
 * @property string $cierra HH:MM:SS
 * @property int $duracion_min_horas
 * @property int $duracion_max_horas
 * @property int $anticipacion_min_horas
 * @property int $anticipacion_max_dias
 * @property int|null $capacidad
 * @property int|null $reservas_por_unidad_mes
 * @property list<int> $dias_semana
 * @property string $costo
 * @property string $garantia
 * @property string|null $reglamento
 */
class ReglaArea extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'reglas_area';

    protected $fillable = [
        'amenidad_id', 'abre', 'cierra', 'duracion_min_horas', 'duracion_max_horas', 'anticipacion_min_horas', 'anticipacion_max_dias',
        'capacidad', 'reservas_por_unidad_mes', 'dias_semana', 'costo', 'garantia', 'reglamento',
    ];

    protected function casts(): array
    {
        return [
            'dias_semana' => 'array',
            'costo' => 'decimal:2',
            'garantia' => 'decimal:2',
            'duracion_min_horas' => 'integer',
            'duracion_max_horas' => 'integer',
            'anticipacion_min_horas' => 'integer',
            'anticipacion_max_dias' => 'integer',
            'capacidad' => 'integer',
            'reservas_por_unidad_mes' => 'integer',
        ];
    }
}
