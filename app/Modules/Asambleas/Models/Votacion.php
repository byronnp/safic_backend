<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * La votación de un punto del orden del día.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $punto_id
 * @property string $estado abierta|cerrada
 * @property bool $secreta
 * @property string $mayoria simple|calificada
 * @property string|null $umbral_pct
 * @property string $base presentes|total
 * @property int|null $abierta_por
 * @property int|null $cerrada_por
 * @property Carbon $abierta_en
 * @property Carbon|null $cerrada_en
 * @property string|null $base_alicuota
 * @property string|null $alicuota_si
 * @property string|null $alicuota_no
 * @property string|null $alicuota_abstencion
 * @property int|null $votos_si
 * @property int|null $votos_no
 * @property int|null $votos_abstencion
 * @property string|null $resultado aprobado|rechazado (en una elección: hubo ganador o no)
 * @property string|null $cargo Solo en elecciones: el cargo que se vota
 * @property int|null $ganador_candidato_id
 */
class Votacion extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ABIERTA = 'abierta';

    public const CERRADA = 'cerrada';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    public const OPCIONES = ['si', 'no', 'abstencion'];

    protected $table = 'votaciones';

    protected $fillable = [
        'asamblea_id', 'punto_id', 'estado', 'secreta', 'mayoria', 'umbral_pct', 'base', 'abierta_por', 'cerrada_por', 'abierta_en', 'cerrada_en',
        'base_alicuota', 'alicuota_si', 'alicuota_no', 'alicuota_abstencion', 'votos_si', 'votos_no', 'votos_abstencion', 'resultado',
        'cargo', 'ganador_candidato_id',
    ];

    protected function casts(): array
    {
        return ['secreta' => 'boolean', 'abierta_en' => 'datetime', 'cerrada_en' => 'datetime'];
    }

    public function esEleccion(): bool
    {
        return $this->cargo !== null;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::ABIERTA;
    }

    /** @return HasMany<Voto, $this> */
    public function votos(): HasMany
    {
        return $this->hasMany(Voto::class);
    }
}
