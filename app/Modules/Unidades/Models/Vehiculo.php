<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use App\Core\Validation\Rules\PlacaEc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property int $condominio_id
 * @property int $unidad_id
 * @property string $placa
 * @property string $tipo
 * @property string|null $marca
 * @property string|null $modelo
 * @property string|null $color
 */
class Vehiculo extends Model implements AuditableContract
{
    use Auditable, BelongsToCondominio, SoftDeletes;

    public const TIPOS = ['auto', 'moto'];

    protected $table = 'vehiculos';

    protected $fillable = ['unidad_id', 'placa', 'tipo', 'marca', 'modelo', 'color'];

    protected static function booted(): void
    {
        static::saving(function (Vehiculo $vehiculo): void {
            $vehiculo->placa = PlacaEc::normalizar($vehiculo->placa) ?? strtoupper($vehiculo->placa);
        });
    }

    /** @return BelongsTo<Unidad, $this> */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }
}
