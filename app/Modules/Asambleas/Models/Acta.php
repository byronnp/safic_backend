<?php

namespace App\Modules\Asambleas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * El acta de una asamblea: su contenido se congela al cerrar y la firman el presidente y el secretario.
 *
 * @property int $id
 * @property int $asamblea_id
 * @property int $numero
 * @property array<string, mixed> $contenido
 * @property string $hash
 * @property string $estado pendiente|firmada
 * @property int|null $presidente_user_id
 * @property string|null $presidente_nombre
 * @property bool $presidente_en_subrogacion
 * @property Carbon|null $firmada_presidente_en
 * @property int|null $secretario_user_id
 * @property string|null $secretario_nombre
 * @property Carbon|null $firmada_secretario_en
 * @property Carbon|null $publicada_en
 */
class Acta extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const PENDIENTE = 'pendiente';

    public const FIRMADA = 'firmada';

    protected $table = 'actas';

    /** El contenido es grande y no cambia: la auditoría registra las firmas, no el JSON. */
    protected array $auditExclude = ['contenido'];

    protected $fillable = [
        'asamblea_id', 'numero', 'contenido', 'hash', 'estado', 'presidente_user_id', 'presidente_nombre', 'presidente_en_subrogacion',
        'firmada_presidente_en', 'secretario_user_id', 'secretario_nombre', 'firmada_secretario_en', 'publicada_en',
    ];

    protected function casts(): array
    {
        return [
            'contenido' => 'array', 'presidente_en_subrogacion' => 'boolean',
            'firmada_presidente_en' => 'datetime', 'firmada_secretario_en' => 'datetime', 'publicada_en' => 'datetime',
        ];
    }

    /**
     * Huella del contenido, independiente del orden de las claves (la base guarda el JSON ordenado a su manera).
     *
     * @param  array<string, mixed>  $contenido
     */
    public static function huellaDe(array $contenido): string
    {
        $ordenar = function (mixed $v) use (&$ordenar): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($ordenar, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return hash('sha256', json_encode($ordenar($contenido), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function estaFirmada(): bool
    {
        return $this->estado === self::FIRMADA;
    }
}
