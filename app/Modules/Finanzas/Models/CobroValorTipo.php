<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Cuota mensual de un tipo de unidad (método de cobro "tipo").
 *
 * @property int $id
 * @property int $condominio_id
 * @property string $tipo_unidad
 * @property string $valor
 */
class CobroValorTipo extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    public const TIPOS_UNIDAD = ['departamento', 'casa', 'local', 'parqueadero', 'bodega'];

    protected $table = 'cobro_valores_tipo';

    protected $fillable = ['tipo_unidad', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:2'];
    }
}
