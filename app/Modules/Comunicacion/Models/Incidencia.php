<?php

namespace App\Modules\Comunicacion\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un problema reportado por un residente o el guardia.
 *
 * @property int $id
 * @property int $numero
 * @property int|null $unidad_id
 * @property int|null $reportado_por
 * @property string $titulo
 * @property string $descripcion
 * @property string $categoria
 * @property string $prioridad alta|media|baja
 * @property string $estado abierto|en_proceso|resuelto|cerrado
 * @property Carbon|null $primera_respuesta_en
 * @property Carbon|null $resuelta_en
 * @property Carbon|null $cerrada_en
 * @property string|null $cierre
 * @property Carbon $created_at
 */
class Incidencia extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const ABIERTO = 'abierto';

    public const EN_PROCESO = 'en_proceso';

    public const RESUELTO = 'resuelto';

    public const CERRADO = 'cerrado';

    public const ESTADOS = [self::ABIERTO, self::EN_PROCESO, self::RESUELTO, self::CERRADO];

    public const CATEGORIAS = ['mantenimiento', 'convivencia', 'seguridad', 'areas_verdes', 'otro'];

    public const PRIORIDADES = ['alta', 'media', 'baja'];

    /** Días que una incidencia resuelta espera la respuesta del residente antes de cerrarse sola. */
    public const DIAS_PARA_CERRAR = 7;

    /** Fotos por incidencia. */
    public const MAXIMO_FOTOS = 3;

    protected $table = 'incidencias';

    protected $fillable = [
        'numero', 'unidad_id', 'reportado_por', 'titulo', 'descripcion', 'categoria', 'prioridad', 'estado', 'primera_respuesta_en',
        'resuelta_en', 'cerrada_en', 'cierre',
    ];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'primera_respuesta_en' => 'datetime', 'resuelta_en' => 'datetime', 'cerrada_en' => 'datetime'];
    }
}
