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
 * @property int|null $anuncio_id Anuncio con confirmación de lectura que sirvió de convocatoria
 * @property Carbon|null $recordada_3d_en
 * @property int|null $instalada_por
 * @property int|null $cerrada_por
 * @property bool $en_subrogacion El vicepresidente instaló en lugar del presidente
 * @property bool|null $mora_cuenta_para_quorum Regla vigente al instalar
 * @property string|null $quorum_alicuota_presente
 * @property string|null $quorum_alicuota_total
 * @property Carbon|null $recordada_2h_en
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
        'convocada_en', 'instalada_en', 'cerrada_en', 'cancelada_en', 'motivo_cancelacion', 'anuncio_id', 'recordada_3d_en', 'recordada_2h_en', 'cerrada_por',
        'instalada_por', 'en_subrogacion', 'mora_cuenta_para_quorum', 'quorum_alicuota_presente', 'quorum_alicuota_total',
    ];

    protected function casts(): array
    {
        return [
            'fecha_hora' => 'datetime', 'convocada_en' => 'datetime', 'instalada_en' => 'datetime', 'cerrada_en' => 'datetime',
            'cancelada_en' => 'datetime', 'recordada_3d_en' => 'datetime', 'recordada_2h_en' => 'datetime',
            'en_subrogacion' => 'boolean', 'mora_cuenta_para_quorum' => 'boolean',
        ];
    }

    /** @return HasMany<AsambleaPunto, $this> */
    public function puntos(): HasMany
    {
        return $this->hasMany(AsambleaPunto::class)->orderBy('orden');
    }

    /** ¿Se está registrando la asistencia? (abierto el registro y hasta que termine la asamblea) */
    public function registroAbierto(): bool
    {
        return in_array($this->estado, [self::EN_REGISTRO, self::INSTALADA, self::EN_VOTACION], true);
    }

    /** Convocada y todavía sin instalar: la convocatoria sigue vigente y se puede reenviar o cancelar. */
    public function estaConvocada(): bool
    {
        return in_array($this->estado, [self::CONVOCADA, self::EN_REGISTRO], true);
    }

    /** Solo un borrador se puede editar: una asamblea convocada ya se notificó a los propietarios. */
    public function esBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }
}
