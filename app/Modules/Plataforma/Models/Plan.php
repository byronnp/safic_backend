<?php

namespace App\Modules\Plataforma\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan de la plataforma. Tabla de plataforma (sin RLS).
 *
 * @property int $id
 * @property string $clave
 * @property string $nombre
 * @property int $max_administrativos
 * @property string $valor_unidad_sugerido
 * @property int $orden
 * @property bool $activo
 */
class Plan extends Model
{
    public const BASICO = 'basico';

    public const PROFESIONAL = 'profesional';

    public const COMPLETO = 'completo';

    protected $table = 'planes';

    protected $fillable = ['clave', 'nombre', 'max_administrativos', 'valor_unidad_sugerido', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'max_administrativos' => 'integer',
            'valor_unidad_sugerido' => 'decimal:2',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeActivos(Builder $query): void
    {
        $query->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }
}
