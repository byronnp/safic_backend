<?php

namespace App\Modules\Garita\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un ingreso (y su salida) registrado por la garita.
 *
 * @property int $id
 * @property int|null $autorizacion_id
 * @property int|null $unidad_id
 * @property string $tipo visita|proveedor|delivery
 * @property string $nombre
 * @property string|null $documento
 * @property string|null $placa
 * @property string|null $destino
 * @property string $autorizado_por qr|llamada|app_residente|administracion
 * @property string $estado dentro|salio
 * @property Carbon $entrada_en
 * @property Carbon|null $salida_en
 * @property int|null $registrado_por
 * @property Carbon|null $anonimizado_en
 */
class AccesoGarita extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const TIPOS = ['visita', 'proveedor', 'delivery'];

    public const AUTORIZADO_POR = ['qr', 'llamada', 'app_residente', 'administracion'];

    public const DENTRO = 'dentro';

    public const SALIO = 'salio';

    protected $table = 'accesos_garita';

    protected $fillable = [
        'autorizacion_id', 'unidad_id', 'tipo', 'nombre', 'documento', 'placa', 'destino', 'autorizado_por', 'estado',
        'entrada_en', 'salida_en', 'registrado_por', 'salida_por', 'anonimizado_en', 'uuid', 'sin_conexion', 'sincronizado_en', 'nota',
    ];

    protected $hidden = ['documento'];

    /** La cédula no se audita en claro. */
    protected $auditExclude = ['documento'];

    protected function casts(): array
    {
        return ['documento' => 'encrypted', 'entrada_en' => 'datetime', 'salida_en' => 'datetime', 'anonimizado_en' => 'datetime', 'sin_conexion' => 'boolean', 'sincronizado_en' => 'datetime'];
    }
}
