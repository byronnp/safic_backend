<?php

namespace App\Modules\Garita\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un paquete recibido en la garita para una unidad.
 *
 * @property int $id
 * @property int $unidad_id
 * @property string $empresa
 * @property string|null $descripcion
 * @property string $foto_ruta
 * @property string $estado en_garita|entregado
 * @property Carbon $recibido_en
 * @property Carbon|null $recordatorio_en
 * @property Carbon|null $entregado_en
 * @property string|null $retira_tipo ocupante|autorizado
 * @property string|null $retira_nombre
 * @property string|null $firma_ruta
 * @property Carbon|null $anonimizado_en
 */
class Paquete extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const EN_GARITA = 'en_garita';

    public const ENTREGADO = 'entregado';

    /** Días en garita a partir de los cuales se resalta y se recuerda al residente. */
    public const DIAS_ATRASADO = 7;

    protected $table = 'paquetes';

    protected $fillable = [
        'unidad_id', 'empresa', 'descripcion', 'foto_ruta', 'estado', 'recibido_en', 'recibido_por', 'recordatorio_en', 'entregado_en',
        'entregado_por', 'retira_tipo', 'retira_nombre', 'firma_ruta', 'anonimizado_en',
    ];

    protected function casts(): array
    {
        return ['recibido_en' => 'datetime', 'recordatorio_en' => 'datetime', 'entregado_en' => 'datetime', 'anonimizado_en' => 'datetime'];
    }
}
