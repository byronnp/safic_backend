<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Database\Factories\UnidadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidad del condominio (departamento, casa, local, parqueadero o bodega).
 *
 * @property int $id
 * @property int $condominio_id
 * @property int|null $bloque_id
 * @property string $codigo
 * @property string $tipo
 * @property int|null $piso
 * @property string $area_m2
 * @property string|null $alicuota
 * @property string|null $cuota_mensual
 * @property string|null $valor_personalizado
 * @property string $responsable_pago
 * @property-read Bloque|null $bloque
 */
class Unidad extends Model
{
    /** @use HasFactory<UnidadFactory> */
    use BelongsToCondominio, HasFactory, SoftDeletes;

    public const TIPOS = ['departamento', 'casa', 'local', 'parqueadero', 'bodega'];

    public const RESPONSABLES_PAGO = ['propietario', 'inquilino'];

    protected $table = 'unidades';

    /** @var array<string, mixed> */
    protected $attributes = ['responsable_pago' => 'propietario'];

    protected $fillable = [
        'bloque_id', 'codigo', 'tipo', 'piso', 'area_m2', 'alicuota',
        'cuota_mensual', 'valor_personalizado', 'responsable_pago',
    ];

    protected function casts(): array
    {
        return [
            'piso' => 'integer',
            'area_m2' => 'decimal:2',
            'alicuota' => 'decimal:4',
            'cuota_mensual' => 'decimal:2',
            'valor_personalizado' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Bloque, $this> */
    public function bloque(): BelongsTo
    {
        return $this->belongsTo(Bloque::class);
    }

    /**
     * Ocupada, arrendada o vacía. Se calcula con los ocupantes vigentes; hasta que
     * existan (S2 · ocupantes) toda unidad está vacía.
     */
    public function estado(): string
    {
        return 'vacia';
    }

    protected static function newFactory(): UnidadFactory
    {
        return UnidadFactory::new();
    }
}
