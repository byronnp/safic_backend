<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use Database\Factories\UnidadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

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
 * @property-read Collection<int, Ocupante> $ocupantesVigentes
 * @property-read Collection<int, Vehiculo> $vehiculos
 * @property-read Collection<int, Mascota> $mascotas
 */
class Unidad extends Model implements AuditableContract
{
    /** @use HasFactory<UnidadFactory> */
    use Auditable, BelongsToCondominio, HasFactory, SoftDeletes;

    public const TIPOS = ['departamento', 'casa', 'local', 'parqueadero', 'bodega'];

    public const RESPONSABLES_PAGO = ['propietario', 'inquilino'];

    public const ESTADOS = ['ocupada', 'arrendada', 'vacia'];

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

    /** @return HasMany<Vehiculo, $this> */
    public function vehiculos(): HasMany
    {
        return $this->hasMany(Vehiculo::class);
    }

    /** @return HasMany<Mascota, $this> */
    public function mascotas(): HasMany
    {
        return $this->hasMany(Mascota::class);
    }

    /** @return HasMany<Ocupante, $this> */
    public function ocupantes(): HasMany
    {
        return $this->hasMany(Ocupante::class);
    }

    /** @return HasMany<Ocupante, $this> */
    public function ocupantesVigentes(): HasMany
    {
        return $this->ocupantes()->vigentes();
    }

    /**
     * Ocupada, arrendada o vacía, según los ocupantes vigentes:
     * - arrendada: hay un inquilino vigente;
     * - ocupada: hay un ocupante principal o un residente vigente;
     * - vacía: nadie vive ahí (un propietario que no reside no la ocupa).
     */
    public function estado(): string
    {
        $vigentes = $this->ocupantesVigentes;

        if ($vigentes->contains('relacion', 'inquilino')) {
            return 'arrendada';
        }

        return $vigentes->contains(fn (Ocupante $o) => $o->es_principal || $o->relacion === 'residente')
            ? 'ocupada'
            : 'vacia';
    }

    /**
     * Filtra por estado con la misma regla que estado().
     *
     * @param  Builder<Unidad>  $query
     */
    public function scopeConEstado(Builder $query, string $estado): void
    {
        $inquilino = fn (Builder $q) => Ocupante::filtrarVigentes($q)->where('relacion', 'inquilino');
        $habitada = fn (Builder $q) => Ocupante::filtrarVigentes($q)->where(fn (Builder $w) => $w->where('es_principal', true)->orWhere('relacion', 'residente'));

        match ($estado) {
            'arrendada' => $query->whereHas('ocupantes', $inquilino),
            'ocupada' => $query->whereDoesntHave('ocupantes', $inquilino)->whereHas('ocupantes', $habitada),
            'vacia' => $query->whereDoesntHave('ocupantes', $inquilino)->whereDoesntHave('ocupantes', $habitada),
            default => null,
        };
    }

    protected static function newFactory(): UnidadFactory
    {
        return UnidadFactory::new();
    }
}
