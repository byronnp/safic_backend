<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Presencia de una unidad en la asamblea.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $unidad_id
 * @property int|null $asistente_persona_id
 * @property string $asistente_nombre
 * @property string $forma presencial|virtual|poder
 * @property int|null $poder_id
 * @property string $canal mesa|app
 * @property int|null $registrada_por
 * @property Carbon $ingreso_en
 * @property Carbon|null $salida_en
 * @property Carbon $ultima_actividad_en
 */
class AsambleaAsistencia extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PRESENCIAL = 'presencial';

    public const VIRTUAL = 'virtual';

    public const PODER = 'poder';

    /** Minutos sin actividad tras los que un asistente virtual deja de contar para el quórum. */
    public const MINUTOS_INACTIVIDAD_VIRTUAL = 10;

    protected $table = 'asamblea_asistencias';

    protected $fillable = [
        'asamblea_id', 'unidad_id', 'asistente_persona_id', 'asistente_nombre', 'forma', 'poder_id', 'canal', 'registrada_por',
        'ingreso_en', 'salida_en', 'ultima_actividad_en',
    ];

    protected function casts(): array
    {
        return ['ingreso_en' => 'datetime', 'salida_en' => 'datetime', 'ultima_actividad_en' => 'datetime'];
    }

    /**
     * Las que siguen en la sala: sin salida y, si son virtuales, con actividad en los últimos 10 minutos.
     *
     * @param  Builder<AsambleaAsistencia>  $q
     * @return Builder<AsambleaAsistencia>
     */
    public function scopePresentes(Builder $q): Builder
    {
        return $q->whereNull('salida_en')->where(function (Builder $q): void {
            $q->where('forma', '!=', self::VIRTUAL)->orWhere('ultima_actividad_en', '>=', now()->subMinutes(self::MINUTOS_INACTIVIDAD_VIRTUAL));
        });
    }
}
