<?php

namespace App\Modules\Garita\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Autorización de un visitante: lo que el residente anuncia a la garita.
 *
 * @property int $id
 * @property int $unidad_id
 * @property int|null $creada_por
 * @property string $token El QR
 * @property string $visitante_nombre
 * @property string|null $visitante_documento
 * @property string|null $placa
 * @property string $tipo unica|recurrente
 * @property Carbon $fecha_desde
 * @property Carbon $fecha_hasta
 * @property string $hora_desde HH:MM:SS
 * @property string $hora_hasta
 * @property list<int>|null $dias_semana
 * @property int $usos
 * @property Carbon|null $revocada_en
 */
class AutorizacionVisita extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const UNICA = 'unica';

    public const RECURRENTE = 'recurrente';

    /** Autorizaciones activas que una unidad puede tener a la vez. */
    public const MAXIMO_ACTIVAS = 50;

    protected $table = 'autorizaciones_visita';

    protected $fillable = [
        'unidad_id', 'creada_por', 'token', 'visitante_nombre', 'visitante_documento', 'placa', 'tipo', 'fecha_desde', 'fecha_hasta',
        'hora_desde', 'hora_hasta', 'dias_semana', 'usos', 'revocada_en',
    ];

    /** La cédula y el token no salen en auditorías ni en JSON por accidente. */
    protected $hidden = ['visitante_documento', 'token'];

    /** No se audita el valor del token ni la cédula (secretos). */
    protected $auditExclude = ['token', 'visitante_documento'];

    protected function casts(): array
    {
        return [
            'visitante_documento' => 'encrypted',
            'fecha_desde' => 'date',
            'fecha_hasta' => 'date',
            'dias_semana' => 'array',
            'usos' => 'integer',
            'revocada_en' => 'datetime',
        ];
    }

    /** activa | revocada | vencida (pasó su fecha, o ya se usó la de un solo ingreso). */
    public function estadoEn(string $hoy): string
    {
        if ($this->revocada_en !== null) {
            return 'revocada';
        }
        if ($this->fecha_hasta->toDateString() < $hoy || ($this->tipo === self::UNICA && $this->usos >= 1)) {
            return 'vencida';
        }

        return 'activa';
    }
}
