<?php

namespace App\Modules\Finanzas\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Un gasto que se repite cada mes. Sin `monto` es de monto variable (luz, agua).
 *
 * @property int $id
 * @property string $nombre
 * @property int $proveedor_id
 * @property string|null $categoria
 * @property string|null $monto
 * @property int $dia_mes
 * @property bool $activo
 * @property bool $aprobacion_automatica
 */
class GastoRecurrente extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio;

    protected $table = 'gastos_recurrentes';

    protected $fillable = ['nombre', 'proveedor_id', 'categoria', 'monto', 'dia_mes', 'activo', 'aprobacion_automatica'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'dia_mes' => 'integer', 'activo' => 'boolean', 'aprobacion_automatica' => 'boolean'];
    }

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** @return HasMany<RecurrenteOcurrencia, $this> */
    public function ocurrencias(): HasMany
    {
        return $this->hasMany(RecurrenteOcurrencia::class, 'gasto_recurrente_id');
    }
}
