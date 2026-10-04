<?php

namespace App\Modules\Unidades\Models;

use App\Core\Tenancy\BelongsToCondominio;
use App\Core\Tenancy\Calendario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Relación de una persona con una unidad, con fechas de vigencia (tabla unidad_persona).
 *
 * @property int $id
 * @property int $condominio_id
 * @property int $unidad_id
 * @property int $persona_id
 * @property string $relacion
 * @property bool $es_principal
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property-read Persona $persona
 * @property-read Unidad $unidad
 */
class Ocupante extends Model
{
    use BelongsToCondominio;

    public const RELACIONES = ['propietario', 'inquilino', 'residente', 'contacto_emergencia'];

    protected $table = 'unidad_persona';

    protected $fillable = ['unidad_id', 'persona_id', 'relacion', 'es_principal', 'fecha_inicio', 'fecha_fin'];

    protected function casts(): array
    {
        return ['es_principal' => 'boolean', 'fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }

    /** @return BelongsTo<Persona, $this> */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class)->withTrashed();
    }

    /** @return BelongsTo<Unidad, $this> */
    public function unidad(): BelongsTo
    {
        return $this->belongsTo(Unidad::class);
    }

    /**
     * Vigentes hoy (en la zona horaria del condominio): ya empezaron y no terminaron.
     *
     * @param  Builder<Ocupante>  $query
     */
    public function scopeVigentes(Builder $query): void
    {
        self::filtrarVigentes($query);
    }

    /**
     * La misma regla que el scope, para usarla dentro de whereHas().
     *
     * @template TBuilder of Builder<Ocupante>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function filtrarVigentes(Builder $query): Builder
    {
        $hoy = app(Calendario::class)->hoy();

        return $query->where('fecha_inicio', '<=', $hoy)
            ->where(fn (Builder $q) => $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $hoy));
    }

    public function vigente(): bool
    {
        $hoy = app(Calendario::class)->hoy();

        return $this->fecha_inicio->toDateString() <= $hoy
            && ($this->fecha_fin === null || $this->fecha_fin->toDateString() >= $hoy);
    }
}
