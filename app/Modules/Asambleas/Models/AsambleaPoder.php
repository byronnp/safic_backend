<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Poder (notarial) o carta con que un propietario designa a un representante para una asamblea (Art. 40).
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $unidad_id
 * @property int|null $otorgante_persona_id
 * @property string $tipo notarial|carta
 * @property string $representante_nombre
 * @property string $representante_tipo_documento
 * @property string $representante_documento
 * @property int|null $representante_persona_id
 * @property string $documento_ruta
 * @property string $documento_nombre
 * @property string $estado pendiente|validado|rechazado|revocado
 * @property string|null $motivo_rechazo
 * @property int|null $cargado_por
 * @property int|null $validado_por
 * @property Carbon|null $validado_en
 * @property Carbon|null $revocado_en
 */
class AsambleaPoder extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PENDIENTE = 'pendiente';

    public const VALIDADO = 'validado';

    public const RECHAZADO = 'rechazado';

    public const REVOCADO = 'revocado';

    public const TIPOS = ['notarial', 'carta'];

    protected $table = 'asamblea_poderes';

    /** Datos personales: la auditoría registra que cambiaron, no sus valores. */
    protected array $auditExclude = ['representante_documento'];

    protected $fillable = [
        'asamblea_id', 'unidad_id', 'otorgante_persona_id', 'tipo', 'representante_nombre', 'representante_tipo_documento', 'representante_documento',
        'representante_persona_id', 'documento_ruta', 'documento_nombre', 'estado', 'motivo_rechazo', 'cargado_por', 'validado_por', 'validado_en', 'revocado_en',
    ];

    protected function casts(): array
    {
        return ['representante_documento' => 'encrypted', 'validado_en' => 'datetime', 'revocado_en' => 'datetime'];
    }

    /** Sigue en pie: espera validación o ya vale. */
    public function estaVigente(): bool
    {
        return in_array($this->estado, [self::PENDIENTE, self::VALIDADO], true);
    }
}
