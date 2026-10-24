<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Asamblea de copropietarios. Estados: borrador → convocada → en_registro → instalada → en_votacion → cerrada → acta_firmada,
 * o cancelada (con motivo) mientras no se haya instalado.
 *
 * @property int $id
 * @property string $titulo
 * @property string $tipo ordinaria|extraordinaria
 * @property string $modalidad presencial|mixta|virtual
 * @property Carbon $fecha_hora
 * @property string|null $lugar
 * @property string|null $enlace_video
 * @property string $estado
 * @property int $convocatoria
 * @property int|null $creada_por
 * @property int|null $convocada_por
 * @property Carbon|null $convocada_en
 * @property Carbon|null $instalada_en
 * @property Carbon|null $cerrada_en
 * @property Carbon|null $cancelada_en
 * @property string|null $motivo_cancelacion
 * @property Carbon $created_at
 */
class Asamblea extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const BORRADOR = 'borrador';

    public const CONVOCADA = 'convocada';

    public const EN_REGISTRO = 'en_registro';

    public const INSTALADA = 'instalada';

    public const EN_VOTACION = 'en_votacion';

    public const CERRADA = 'cerrada';

    public const ACTA_FIRMADA = 'acta_firmada';

    public const CANCELADA = 'cancelada';

    public const TIPOS = ['ordinaria', 'extraordinaria'];

    public const MODALIDADES = ['presencial', 'mixta', 'virtual'];

    /** Minutos que deben pasar desde la primera convocatoria para instalar con los presentes (Art. 39). */
    public const MINUTOS_SEGUNDA_CONVOCATORIA = 60;

    protected $table = 'asambleas';

    protected $fillable = [
        'titulo', 'tipo', 'modalidad', 'fecha_hora', 'lugar', 'enlace_video', 'estado', 'convocatoria', 'creada_por', 'convocada_por',
        'convocada_en', 'instalada_en', 'cerrada_en', 'cancelada_en', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime', 'convocada_en' => 'datetime', 'instalada_en' => 'datetime', 'cerrada_en' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
    }

    /** @return HasMany<AsambleaPunto, $this> */
    public function puntos(): HasMany
    {
        return $this->hasMany(AsambleaPunto::class)->orderBy('orden');
    }

    /** Solo un borrador se puede editar: una asamblea convocada ya se notificó a los propietarios. */
    public function esBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }
}
